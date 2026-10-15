<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Models\Company;
use App\Models\PhysicalCount;
use App\Models\PhysicalCountLine;
use App\Models\Product;
use App\Models\ProductCode;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\GenerateAdjustmentJournalEntry;
use App\Services\RegisterInventoryMovement;
use App\Support\CostosInventario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Toma física masiva (T5.2). Se abre un borrador con los productos de una bodega, se captura lo contado (en blanco =
 * "no contado": nunca ajusta) y al aplicar, en UNA transacción, se crea un movimiento por cada diferencia y UN asiento
 * para toda la toma. Aplicada, es inmutable.
 *
 * Los productos con series no entran: cada unidad tiene su número y se ajusta por serie en Ajuste de inventario; el
 * borrador los lista en `omitidos_series`. Al aplicar manda el stock ACTUAL de la bodega, no el que había al abrir
 * el borrador (la respuesta marca `stock_cambio` y qué productos cambiaron).
 */
class PhysicalCountController extends Controller
{
    use ExportsReports;

    public function index(Request $r)
    {
        $companyId = $this->empresa($r);

        $conteos = PhysicalCount::with('warehouse:id,codigo,nombre')
            ->withCount(['lines', 'lines as contados_count' => fn ($q) => $q->whereNotNull('contado')])
            ->where('company_id', $companyId)
            ->when($r->query('estado'), fn ($q, $e) => $q->where('estado', $e))
            ->when($r->query('warehouse_id'), fn ($q, $w) => $q->where('warehouse_id', $w))
            ->orderByDesc('id')->get();

        // Un borrador todavía no guardó sus totales: salen de las líneas contadas, con el mismo redondeo que el detalle
        $borradores = $conteos->where('estado', PhysicalCount::BORRADOR)->pluck('id');
        $lineas = $borradores->isEmpty() ? collect() : PhysicalCountLine::whereIn('physical_count_id', $borradores)->whereNotNull('contado')
            ->get(['physical_count_id', 'stock_sistema', 'costo_promedio', 'contado'])->groupBy('physical_count_id');

        return $conteos->map(function (PhysicalCount $c) use ($lineas) {
            $sobrantes = (float) $c->valor_sobrantes;
            $faltantes = (float) $c->valor_faltantes;
            if ($c->esBorrador()) {
                [$sobrantes, $faltantes] = $this->totales(($lineas[$c->id] ?? collect())->map(function ($l) {
                    $dif = round((float) $l->contado - (float) $l->stock_sistema, 4);

                    return $dif === 0.0 ? null : round($dif * (float) $l->costo_promedio, 2);
                })->filter(fn ($v) => $v !== null)->all());
            }

            return [
                'id' => $c->id,
                'numero' => $c->numero,
                'estado' => $c->estado,
                'fecha' => $c->fecha?->toDateString(),
                'motivo' => $c->motivo,
                'warehouse' => $c->warehouse ? ['id' => $c->warehouse->id, 'codigo' => $c->warehouse->codigo, 'nombre' => $c->warehouse->nombre] : null,
                'productos' => (int) $c->lines_count,
                'contados' => (int) $c->contados_count,
                'valor_sobrantes' => $sobrantes,
                'valor_faltantes' => $faltantes,
                'applied_at' => $c->applied_at?->toIso8601String(),
            ];
        })->values();
    }

    /**
     * Abre un borrador con todos los productos que manejan existencias y tienen stock en la bodega (sin series, servicios
     * ni combos). `buscar` recorta por código o nombre; `incluir_sin_stock` agrega también los que la bodega no tiene
     * (por si hay mercadería que el sistema no conoce en esa bodega).
     */
    public function store(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'incluir_sin_stock' => ['sometimes', 'boolean'],
        ], [
            'warehouse_id.required' => 'Elige la bodega que vas a contar.',
        ]);
        $companyId = $this->empresa($r);
        $bodega = Warehouse::where('company_id', $companyId)->whereKey($d['warehouse_id'])->first();
        if (! $bodega) {
            throw ValidationException::withMessages(['warehouse_id' => ['La bodega elegida no existe en esta empresa.']]);
        }

        $conteo = DB::transaction(function () use ($d, $companyId, $bodega, $r) {
            $existencias = WarehouseStock::where('warehouse_id', $bodega->id)->pluck('stock', 'product_id')
                ->map(fn ($s) => round((float) $s, 4));

            $candidatos = Product::where('company_id', $companyId)->where('tipo', '!=', 'servicio')
                ->where(fn ($q) => $q->whereNull('es_combo')->orWhere('es_combo', false))
                ->when(trim((string) ($d['buscar'] ?? '')) !== '', function ($q) use ($d) {
                    $texto = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($d['buscar']))).'%';
                    $q->where(fn ($w) => $w->whereRaw("LOWER(codigo) LIKE ? ESCAPE '!'", [$texto])
                        ->orWhereRaw("LOWER(descripcion) LIKE ? ESCAPE '!'", [$texto])
                        ->orWhereHas('codes', fn ($c) => $c->whereRaw("LOWER(codigo) LIKE ? ESCAPE '!'", [$texto])));
                })
                ->orderBy('codigo')->get(['id', 'codigo', 'descripcion', 'maneja_series', 'costo_promedio']);

            $incluirTodos = (bool) ($d['incluir_sin_stock'] ?? false);
            $omitidos = [];
            $lineas = [];
            foreach ($candidatos as $p) {
                $stock = (float) ($existencias[$p->id] ?? 0);
                if ($p->maneja_series) {
                    if (abs($stock) >= 0.0001) {
                        $omitidos[] = ['codigo' => $p->codigo, 'descripcion' => $p->descripcion, 'stock' => $stock];
                    }
                    continue;
                }
                if (! $incluirTodos && abs($stock) < 0.0001) {
                    continue;
                }
                $lineas[] = [
                    'product_id' => $p->id, 'stock_sistema' => $stock, 'costo_promedio' => (float) $p->costo_promedio,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }

            $conteo = PhysicalCount::create([
                'company_id' => $companyId,
                'warehouse_id' => $bodega->id,
                'numero' => $this->siguienteNumero($companyId),
                'estado' => PhysicalCount::BORRADOR,
                'motivo' => isset($d['motivo']) && trim($d['motivo']) !== '' ? trim($d['motivo']) : null,
                'fecha' => now()->toDateString(),
                'omitidos_series' => $omitidos,
                'user_id' => Auth::id(),
            ]);
            foreach (array_chunk($lineas, 400) as $trozo) {
                PhysicalCountLine::insert(array_map(fn ($l) => $l + ['physical_count_id' => $conteo->id], $trozo));
            }

            return $conteo;
        });

        return response()->json($this->detalle($conteo), 201);
    }

    public function show(Request $r, PhysicalCount $physicalCount)
    {
        $this->exigirDeLaEmpresa($r, $physicalCount);

        return $this->detalle($physicalCount);
    }

    /**
     * Guarda lo contado en bloque. `contado` null (o en blanco) deja el producto "no contado"; 0 es una cantidad contada.
     * Solo mientras la toma está en borrador.
     */
    public function update(Request $r, PhysicalCount $physicalCount)
    {
        $this->exigirDeLaEmpresa($r, $physicalCount);
        $d = $r->validate([
            'motivo' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lineas' => ['sometimes', 'array', 'max:20000'],
            'lineas.*.product_id' => ['required', 'integer'],
            'lineas.*.contado' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ], [
            'lineas.*.contado.min' => 'La cantidad contada no puede ser negativa.',
            'lineas.*.contado.numeric' => 'La cantidad contada debe ser un número.',
            'lineas.*.contado.max' => 'La cantidad contada es demasiado grande.',
        ]);

        return DB::transaction(function () use ($physicalCount, $d) {
            $conteo = PhysicalCount::whereKey($physicalCount->id)->lockForUpdate()->firstOrFail();
            if (! $conteo->esBorrador()) {
                return response()->json(['message' => 'Esta toma física ya fue aplicada: no se puede modificar ni aplicar otra vez.'], 422);
            }

            $propias = $conteo->lines()->pluck('id', 'product_id');
            foreach ($d['lineas'] ?? [] as $l) {
                if (! isset($propias[$l['product_id']])) {
                    $codigo = Product::whereKey($l['product_id'])->value('codigo') ?? '#'.$l['product_id'];
                    throw ValidationException::withMessages(['lineas' => ["El producto {$codigo} no está en esta toma física."]]);
                }
            }
            foreach ($d['lineas'] ?? [] as $l) {
                if (array_key_exists('contado', $l)) {
                    PhysicalCountLine::whereKey($propias[$l['product_id']])
                        ->update(['contado' => $l['contado'] === null ? null : round((float) $l['contado'], 4), 'updated_at' => now()]);
                }
            }
            if (array_key_exists('motivo', $d)) {
                $conteo->update(['motivo' => $d['motivo'] !== null && trim($d['motivo']) !== '' ? trim($d['motivo']) : null]);
            }

            return response()->json($this->detalle($conteo->fresh()));
        });
    }

    /**
     * Aplica la toma: por cada producto contado, diferencia = contado - stock ACTUAL de la bodega; un ingreso (sobra) o un
     * egreso (falta) al costo promedio vigente, con el concepto "Toma física TF-000001" y la toma como origen, y UN asiento
     * para todo. 422 si ya está aplicada, si no hay diferencias o si el mes de hoy está cerrado; en ese caso no cambia nada.
     */
    public function aplicar(Request $r, PhysicalCount $physicalCount)
    {
        $this->exigirDeLaEmpresa($r, $physicalCount);

        try {
            $resultado = DB::transaction(fn () => $this->aplicarDentro($physicalCount->id));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // Ya aplicada, sin diferencias, stock insuficiente por una venta simultánea...: un aviso, no un error 500
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($resultado);
    }

    private function aplicarDentro(int $id): array
    {
        $conteo = PhysicalCount::whereKey($id)->lockForUpdate()->firstOrFail();
        if (! $conteo->esBorrador()) {
            throw new \InvalidArgumentException('Esta toma física ya fue aplicada: no se puede modificar ni aplicar otra vez.');
        }

        $contadas = $conteo->lines()->whereNotNull('contado')->with('product:id,codigo,descripcion,maneja_series')->get()
            ->sortBy(fn ($l) => mb_strtolower((string) $l->product?->codigo), SORT_NATURAL)->values();
        $actuales = WarehouseStock::where('warehouse_id', $conteo->warehouse_id)
            ->whereIn('product_id', $contadas->pluck('product_id'))->pluck('stock', 'product_id');

        $plan = [];
        $cambios = [];
        foreach ($contadas as $l) {
            $actual = round((float) ($actuales[$l->product_id] ?? 0), 4);
            $dif = round((float) $l->contado - $actual, 4);
            if (abs($actual - (float) $l->stock_sistema) >= 0.0001) {
                $cambios[] = ['codigo' => $l->product->codigo, 'descripcion' => $l->product->descripcion,
                    'stock_sistema' => (float) $l->stock_sistema, 'stock_actual' => $actual];
            }
            $plan[] = [$l, $actual, $dif];
        }

        $conDiferencia = array_values(array_filter($plan, fn ($p) => abs($p[2]) >= 0.0001));
        if (! $conDiferencia) {
            throw new \InvalidArgumentException('No hay diferencias que ajustar.');
        }

        $hoy = now()->toDateString();
        $servicio = app(RegisterInventoryMovement::class);
        $sobrantes = [];
        $faltantes = [];
        foreach ($conDiferencia as [$l, $actual, $dif]) {
            $producto = Product::findOrFail($l->product_id);          // fresco: el costo promedio de ahora
            if ($producto->maneja_series) {
                throw new \InvalidArgumentException("El producto {$producto->codigo} ahora maneja series: ajústalo por serie en Ajuste de inventario.");
            }
            $tipo = $dif > 0 ? 'ingreso' : 'egreso';
            $mov = $servicio->handle(
                $producto, $tipo, abs($dif), (float) $producto->costo_promedio,
                'Toma física '.$conteo->numero, $hoy, $conteo->warehouse_id, origen: $conteo
            );
            $valor = CostosInventario::valor($mov);
            $tipo === 'ingreso' ? $sobrantes[] = $valor : $faltantes[] = $valor;
            $l->update([
                'stock_al_aplicar' => $actual, 'diferencia' => $dif,
                'valor' => $tipo === 'ingreso' ? $valor : -$valor, 'inventory_movement_id' => $mov->id,
            ]);
        }
        // Lo contado que coincide con el sistema no mueve nada, pero queda anotado contra qué se comparó
        foreach ($plan as [$l, $actual, $dif]) {
            if (abs($dif) < 0.0001) {
                $l->update(['stock_al_aplicar' => $actual, 'diferencia' => 0, 'valor' => 0]);
            }
        }

        $totalSobrantes = round(array_sum($sobrantes), 2);
        $totalFaltantes = round(array_sum($faltantes), 2);
        // Si el mes de hoy está cerrado, JournalEntry lo rechaza aquí y se deshace todo lo de arriba
        $asiento = app(GenerateAdjustmentJournalEntry::class)->handleTomaFisica(
            $conteo, $totalSobrantes, $totalFaltantes, count($sobrantes) > 0, count($faltantes) > 0, $hoy
        );

        $conteo->update([
            'estado' => PhysicalCount::APLICADA, 'applied_at' => now(),
            'valor_sobrantes' => $totalSobrantes, 'valor_faltantes' => $totalFaltantes,
        ]);

        return [
            'ok' => true,
            'id' => $conteo->id,
            'numero' => $conteo->numero,
            'estado' => PhysicalCount::APLICADA,
            'movimientos' => count($conDiferencia),
            'asiento' => $asiento->numero,
            'valor_sobrantes' => $totalSobrantes,
            'valor_faltantes' => $totalFaltantes,
            'stock_cambio' => count($cambios) > 0,
            'productos_con_cambio' => $cambios,
        ];
    }

    public function destroy(Request $r, PhysicalCount $physicalCount)
    {
        $this->exigirDeLaEmpresa($r, $physicalCount);

        return DB::transaction(function () use ($physicalCount) {
            $conteo = PhysicalCount::whereKey($physicalCount->id)->lockForUpdate()->firstOrFail();
            if (! $conteo->esBorrador()) {
                return response()->json(['message' => 'Una toma física aplicada no se elimina: su asiento y sus movimientos ya existen.'], 422);
            }
            $conteo->lines()->delete();
            $conteo->delete();

            return response()->noContent();
        });
    }

    /**
     * Hoja para imprimir y llevar a la bodega (PDF o Excel/CSV). `ciego=1` quita el stock del sistema para que quien
     * cuenta no se guíe por él.
     */
    public function hoja(Request $r, PhysicalCount $physicalCount)
    {
        $this->exigirDeLaEmpresa($r, $physicalCount);
        $ciego = $r->boolean('ciego');

        $lineas = $physicalCount->lines()->with('product:id,codigo,descripcion')->get()
            ->sortBy(fn ($l) => mb_strtolower((string) $l->product?->codigo), SORT_NATURAL)->values();

        $headers = $ciego ? ['Código', 'Producto', 'Contado'] : ['Código', 'Producto', 'Stock del sistema', 'Contado'];
        $pdf = $r->query('formato') === 'pdf';
        $texto = fn ($v) => $pdf ? e((string) $v) : (string) $v;      // el PDF arma HTML con las celdas tal cual
        $filas = $lineas->map(function ($l) use ($ciego, $texto) {
            $base = [$texto($l->product?->codigo), $texto($l->product?->descripcion)];

            return $ciego ? [...$base, ''] : [...$base, number_format((float) $l->stock_sistema, 2, '.', ''), ''];
        })->all();

        $bodega = $physicalCount->warehouse()->value('nombre');
        $nombre = 'hoja-conteo-'.$physicalCount->numero;

        return $pdf
            ? $this->pdfResponse('Hoja de conteo '.e($physicalCount->numero).' — '.e((string) $bodega), $headers, $filas, $nombre)
            : $this->csvResponse($headers, $filas, $nombre, true);
    }

    // ------------------------------------------------------------------------------------------------ ayudantes

    /** El detalle completo: cabecera, líneas con su diferencia y valor, y el resumen. */
    private function detalle(PhysicalCount $c): array
    {
        $c->loadMissing('warehouse:id,codigo,nombre');
        $lineas = $c->lines()->with('product:id,codigo,descripcion')->get()
            ->sortBy(fn ($l) => mb_strtolower((string) $l->product?->codigo), SORT_NATURAL)->values();
        $esBorrador = $c->esBorrador();

        $actuales = $esBorrador
            ? WarehouseStock::where('warehouse_id', $c->warehouse_id)->whereIn('product_id', $lineas->pluck('product_id'))->pluck('stock', 'product_id')
            : collect();
        // Otros códigos del producto (códigos de barras): el lector de la pantalla también los reconoce
        $alternos = ProductCode::whereIn('product_id', $lineas->pluck('product_id'))->get(['product_id', 'codigo'])->groupBy('product_id');

        $valores = [];
        $filas = $lineas->map(function (PhysicalCountLine $l) use ($esBorrador, $actuales, $alternos, &$valores) {
            $contado = $l->contado === null ? null : (float) $l->contado;
            if ($esBorrador) {
                $diferencia = $contado === null ? null : round($contado - (float) $l->stock_sistema, 4);
                $valor = $diferencia === null ? null : round($diferencia * (float) $l->costo_promedio, 2);
                $actual = round((float) ($actuales[$l->product_id] ?? 0), 4);
            } else {
                $diferencia = $l->diferencia === null ? null : (float) $l->diferencia;
                $valor = $l->valor === null ? null : (float) $l->valor;
                $actual = null;
            }
            if ($valor !== null && $diferencia !== null && abs($diferencia) >= 0.0001) {
                $valores[] = $valor;
            }

            return [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'codigo' => $l->product?->codigo,
                'descripcion' => $l->product?->descripcion,
                'codigos_alternos' => ($alternos[$l->product_id] ?? collect())->pluck('codigo')->values()->all(),
                'stock_sistema' => (float) $l->stock_sistema,
                'costo_promedio' => (float) $l->costo_promedio,
                'contado' => $contado,
                'diferencia' => $diferencia,
                'valor' => $valor,
                'stock_al_aplicar' => $l->stock_al_aplicar === null ? null : (float) $l->stock_al_aplicar,
                'stock_actual' => $actual,
                'stock_cambio' => $esBorrador && $contado !== null && abs($actual - (float) $l->stock_sistema) >= 0.0001,
            ];
        })->all();

        [$sobrantes, $faltantes] = $this->totales($valores);

        return [
            'id' => $c->id,
            'numero' => $c->numero,
            'estado' => $c->estado,
            'fecha' => $c->fecha?->toDateString(),
            'motivo' => $c->motivo,
            'applied_at' => $c->applied_at?->toIso8601String(),
            'warehouse' => $c->warehouse ? ['id' => $c->warehouse->id, 'codigo' => $c->warehouse->codigo, 'nombre' => $c->warehouse->nombre] : null,
            'omitidos_series' => $c->omitidos_series ?? [],
            'lineas' => $filas,
            'resumen' => [
                'productos' => count($filas),
                'contados' => count(array_filter($filas, fn ($f) => $f['contado'] !== null)),
                'con_diferencia' => count($valores),
                'valor_sobrantes' => $sobrantes,
                'valor_faltantes' => $faltantes,
            ],
            'asiento' => $esBorrador ? null : $c->asiento()?->numero,
        ];
    }

    /** @param float[] $valores diferencias valoradas (positivo = sobra, negativo = falta) @return array{0:float,1:float} sobrantes, faltantes */
    private function totales(array $valores): array
    {
        return [
            round(array_sum(array_filter($valores, fn ($v) => $v > 0)), 2),
            round(-array_sum(array_filter($valores, fn ($v) => $v < 0)), 2),
        ];
    }

    /**
     * TF-000001, TF-000002…: el siguiente después del mayor que exista en la empresa. La fila de la empresa se bloquea para
     * que dos aperturas a la vez no tomen el mismo número, y no se toca su secuencial (que es el de las facturas); el
     * índice único (empresa, número) es la última red.
     */
    private function siguienteNumero(int $companyId): string
    {
        Company::whereKey($companyId)->lockForUpdate()->value('id');
        $max = PhysicalCount::where('company_id', $companyId)->pluck('numero')
            ->map(fn ($n) => (int) substr((string) $n, 3))->max() ?? 0;

        return sprintf('TF-%06d', $max + 1);
    }

    /** La empresa de la petición; quien no es administrador solo trabaja en la suya. */
    private function empresa(Request $r): int
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'exists:companies,id']]);
        $usuario = $r->user();
        abort_if(! $usuario?->esAdmin() && $usuario?->company_id && (int) $usuario->company_id !== (int) $d['company_id'], 404, 'No se encontró la empresa.');

        return (int) $d['company_id'];
    }

    /** La toma tiene que ser de la empresa que la pide: si no, 404 sin dar un solo dato. */
    private function exigirDeLaEmpresa(Request $r, PhysicalCount $c): void
    {
        abort_unless($this->empresa($r) === (int) $c->company_id, 404, 'No se encontró la toma física en esta empresa.');
    }
}
