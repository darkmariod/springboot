<?php

namespace App\Support;

use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockTransformation;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\RegisterInventoryMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Fraccionamiento y conversión de artículos (T3.7). Los dos hacen lo mismo con el kárdex:
 *   sale el producto de origen a su costo promedio y entra el de destino de modo que el VALOR del inventario no cambie:
 *   costo unitario de destino = (cantidad que sale x costo de salida) / cantidad que entra.
 * Por eso no llevan asiento (Inventario -> Inventario, misma cuenta 1.1.05) y no gastan el secuencial de la empresa:
 * dejan un StockTransformation con su propio número por empresa y clase (FRA-000001, CONV-000001), al que apuntan
 * los dos movimientos del kárdex.
 *
 * Redondeo: el kárdex guarda el costo a 4 decimales y valora cada movimiento a 2. El costo del destino se TRUNCA a
 * 4 decimales (nunca se redondea hacia arriba, así el valor jamás se infla); si con un solo costo el valor se desvía de
 * un centavo, el destino entra en dos tramos (unos pocos a 0.0001 más) hasta que el valor sale exacto.
 */
final class TransformacionesStock
{
    private const PREFIJO = [StockTransformation::FRACCIONAMIENTO => 'FRA', StockTransformation::CONVERSION => 'CONV'];
    private const ETIQUETA = [StockTransformation::FRACCIONAMIENTO => 'Fraccionamiento', StockTransformation::CONVERSION => 'Conversión'];
    /** Un centavo: lo máximo que puede separarse lo que sale de lo que entra. */
    public const TOLERANCIA = 0.01;
    /** Cuántas unidades arriba y abajo de lo calculado se prueban al repartir el resto (se descartan las que caen en medio centavo). */
    private const AJUSTES = [0, -1, 1, -2, 2, -3, 3, -4, 4, -5, 5, -6, 6, -7, 7, -8, 8, -9, 9, -10, 10, -12, 12, -15, 15, -20, 20, -30, 30, -50, 50];

    /**
     * Hace la operación completa en una transacción: un rechazo (TransformacionInvalida, 404 si un producto no es de
     * la empresa) no deja nada a medias.
     *
     * @param  string  $tipo  StockTransformation::FRACCIONAMIENTO | CONVERSION
     * @return array{transformacion:StockTransformation,salida:InventoryMovement,entradas:\Illuminate\Support\Collection,costo_unitario:float,diferencia:float,bodega:?Warehouse}
     *
     * @throws TransformacionInvalida
     */
    public static function ejecutar(
        int $companyId,
        string $tipo,
        int $origenId,
        int $destinoId,
        float $cantOrigen,
        float $cantDestino,
        ?int $warehouseId = null,
        ?string $motivo = null,
    ): array {
        return DB::transaction(function () use ($companyId, $tipo, $origenId, $destinoId, $cantOrigen, $cantDestino, $warehouseId, $motivo) {
            $origen = Product::where('company_id', $companyId)->findOrFail($origenId);
            $destino = Product::where('company_id', $companyId)->findOrFail($destinoId);

            if ($origen->id === $destino->id) {
                throw new TransformacionInvalida('El producto de origen y el de destino deben ser distintos.');
            }
            self::validarProducto($origen);
            self::validarProducto($destino);

            $cantOrigen = round($cantOrigen, RegisterInventoryMovement::DEC_CANTIDAD);
            $cantDestino = round($cantDestino, RegisterInventoryMovement::DEC_CANTIDAD);
            if ($cantOrigen <= 0 || $cantDestino <= 0) {
                throw new TransformacionInvalida('Las cantidades deben ser mayores a cero.');
            }

            $bodega = self::bodega($companyId, $origen, $cantOrigen, $warehouseId);

            $documento = StockTransformation::create([
                'company_id' => $companyId,
                'tipo' => $tipo,
                'numero' => self::siguienteNumero($companyId, $tipo),
                'product_from_id' => $origen->id,
                'product_to_id' => $destino->id,
                'cantidad_origen' => $cantOrigen,
                'cantidad_destino' => $cantDestino,
                'valor_total' => 0,
                'warehouse_id' => $bodega?->id,
                'motivo' => $motivo !== null && trim($motivo) !== '' ? trim($motivo) : null,
                'user_id' => Auth::id(),
                'fecha' => now()->toDateString(),
            ]);

            $servicio = app(RegisterInventoryMovement::class);
            $fecha = $documento->fecha->toDateString();
            $texto = self::ETIQUETA[$tipo].' '.$documento->numero.': '.self::cantidad($cantOrigen).' → '.self::cantidad($cantDestino);
            $concepto = fn (string $lado) => mb_substr($texto.' ('.$lado.')'.($documento->motivo ? '. '.$documento->motivo : ''), 0, 255);

            try {
                $salida = $servicio->handle(
                    $origen, 'egreso', $cantOrigen, (float) $origen->costo_promedio,
                    $concepto('a '.$destino->codigo), $fecha, $bodega?->id, origen: $documento
                );
            } catch (\RuntimeException $e) {
                throw new TransformacionInvalida($e->getMessage());   // p. ej. otro usuario vendió el stock mientras tanto
            }

            // Lo que valió la salida en el kárdex (al promedio vigente en ese punto): eso es lo que debe entrar
            $valor = CostosInventario::valor($salida);
            [$tramos, $costo] = self::tramos($valor, $cantDestino);

            $entradas = collect();
            foreach ($tramos as [$cant, $costoTramo]) {
                $entradas->push($servicio->handle(
                    $destino, 'ingreso', $cant, $costoTramo,
                    $concepto('desde '.$origen->codigo), $fecha, $bodega?->id, origen: $documento
                ));
            }

            $entra = round($entradas->sum(fn (InventoryMovement $m) => CostosInventario::valor($m)), 2);
            $diferencia = round($valor - $entra, 2);
            if (abs($diferencia) > self::TOLERANCIA) {
                // No debería pasar; si pasa, se deshace todo antes que dejar el inventario con otro valor
                throw new TransformacionInvalida('No se pudo conservar el valor del inventario con esas cantidades: revisa los decimales.');
            }

            $documento->update(['valor_total' => $valor]);

            return [
                'transformacion' => $documento,
                'salida' => $salida,
                'entradas' => $entradas,
                'costo_unitario' => $costo,
                'diferencia' => $diferencia,
                'bodega' => $bodega,
            ];
        });
    }

    /** Respuesta JSON de la API con lo que la pantalla muestra al terminar. */
    public static function respuesta(array $r): array
    {
        /** @var StockTransformation $t */
        $t = $r['transformacion'];

        return [
            'ok' => true,
            'id' => $t->id,
            'numero' => $t->numero,
            'tipo' => $t->tipo,
            'producto_origen' => $t->productoOrigen->codigo,
            'producto_destino' => $t->productoDestino->codigo,
            'cantidad_origen' => (float) $t->cantidad_origen,
            'cantidad_destino' => (float) $t->cantidad_destino,
            'costo_unitario_destino' => $r['costo_unitario'],
            'valor_total' => (float) $t->valor_total,
            'diferencia_redondeo' => $r['diferencia'],
            'bodega' => $r['bodega']?->nombre,
            'movimiento_salida' => $r['salida']->id,
            'movimiento_entrada' => $r['entradas']->first()->id,
            'movimientos_entrada' => $r['entradas']->pluck('id')->all(),
        ];
    }

    /** Servicios, combos y productos con series no tienen existencias que se puedan partir o convertir. */
    private static function validarProducto(Product $p): void
    {
        if ($p->tipo === 'servicio') {
            throw new TransformacionInvalida("El producto {$p->codigo} es un servicio: no maneja existencias, así que no se puede fraccionar ni convertir.");
        }
        if ($p->es_combo) {
            throw new TransformacionInvalida("El producto {$p->codigo} es un combo: no maneja existencias propias, así que no se puede fraccionar ni convertir.");
        }
        if ($p->maneja_series) {
            throw new TransformacionInvalida(
                "El producto {$p->codigo} maneja series: cada unidad tiene su propio número de serie y al fraccionarla o convertirla "
                .'esas series quedarían sin producto. Da de baja las series con un ajuste de inventario y registra el producto nuevo con su compra.'
            );
        }
    }

    /**
     * Bodega donde ocurre la operación. Sin bodega elegida: la principal si tiene el stock; si no, la primera que lo
     * tenga. 422 si la elegida no es de la empresa o no alcanza el stock. Una empresa sin bodegas (datos antiguos)
     * opera contra el stock total del producto.
     */
    private static function bodega(int $companyId, Product $origen, float $cant, ?int $warehouseId): ?Warehouse
    {
        if ($warehouseId) {
            $bodega = Warehouse::where('company_id', $companyId)->whereKey($warehouseId)->first();
            if (! $bodega) {
                throw new TransformacionInvalida('La bodega elegida no existe en esta empresa.');
            }
        } else {
            $bodegas = Warehouse::where('company_id', $companyId)->orderByDesc('por_defecto')->orderBy('id')->get();
            if ($bodegas->isEmpty()) {
                $bodega = null;
            } else {
                $stocks = WarehouseStock::where('product_id', $origen->id)->pluck('stock', 'warehouse_id');
                $bodega = $bodegas->first(fn (Warehouse $w) => round((float) ($stocks[$w->id] ?? 0) - $cant, 4) >= 0)
                    ?? $bodegas->first(fn (Warehouse $w) => (float) ($stocks[$w->id] ?? 0) > 0)
                    ?? $bodegas->first();
            }
        }

        $disponible = $bodega
            ? (float) (WarehouseStock::where('warehouse_id', $bodega->id)->where('product_id', $origen->id)->value('stock') ?? 0)
            : (float) $origen->stock;
        if (round($disponible - $cant, 4) < 0) {
            $donde = $bodega ? " en la bodega {$bodega->nombre}" : '';

            throw new TransformacionInvalida(
                "Stock insuficiente de {$origen->codigo}{$donde}: hay ".self::cantidad($disponible).', se piden '.self::cantidad($cant).'.',
                ['disponible' => $disponible]
            );
        }

        return $bodega;
    }

    /**
     * FRA-000001, FRA-000002… / CONV-000001…: el siguiente después del mayor que exista en la empresa para esa clase.
     * La fila de la empresa se bloquea para que dos operaciones a la vez no tomen el mismo número (sin tocar su
     * secuencial, que es el de las facturas); el índice único (empresa, número) es la última red.
     */
    private static function siguienteNumero(int $companyId, string $tipo): string
    {
        Company::whereKey($companyId)->lockForUpdate()->value('id');
        $prefijo = self::PREFIJO[$tipo];
        $max = StockTransformation::where('company_id', $companyId)->where('tipo', $tipo)->pluck('numero')
            ->map(fn ($n) => (int) substr((string) $n, strlen($prefijo) + 1))->max() ?? 0;

        return sprintf('%s-%06d', $prefijo, $max + 1);
    }

    /**
     * Cómo entra el destino para que su valor sea el que salió. Los valores se comparan en centavos y con aritmética
     * entera: con decimales flotantes un valor justo en medio centavo (12.345) se redondea para un lado o para el otro,
     * así que de cada tramo se toma el rango posible [piso, techo] y solo sirve un reparto cuyo techo no pase de lo que
     * salió (jamás infla) y cuyo piso se acerque lo más posible.
     *
     * @return array{0:list<array{0:float,1:float}>,1:float} los tramos [cantidad, costo unitario] y el costo base
     */
    private static function tramos(float $valor, float $cant): array
    {
        $paso = 1 / (10 ** RegisterInventoryMovement::DEC_COSTO);               // 0.0001
        $base = round(floor(round($valor / $cant / $paso, 6)) * $paso, RegisterInventoryMovement::DEC_COSTO);   // truncado: nunca infla
        $valorC = (int) round($valor * 100);

        [$unicoPiso] = self::centavos($cant, $base);
        if ($valorC <= $unicoPiso) {
            return [[[$cant, $base]], $base];                                     // un solo tramo ya da el valor exacto
        }

        // El resto que el costo truncado no cubre se reparte subiendo 0.0001 a unas pocas unidades
        $costoAlto = round($base + $paso, RegisterInventoryMovement::DEC_COSTO);
        $n0 = (int) round(($valor - $cant * $base) / $paso);
        $mejor = null;
        foreach (self::AJUSTES as $ajuste) {
            $n = $n0 + $ajuste;
            $resto = round($cant - $n, RegisterInventoryMovement::DEC_CANTIDAD);
            if ($n < 1 || $resto <= 0) {
                continue;
            }
            [$altoPiso, $altoTecho] = self::centavos((float) $n, $costoAlto);
            [$bajoPiso, $bajoTecho] = self::centavos($resto, $base);
            if ($altoTecho + $bajoTecho > $valorC) {
                continue;                                                         // en el peor redondeo inflaría
            }
            $desvio = $valorC - ($altoPiso + $bajoPiso);                          // peor desvío posible
            if ($mejor === null || $desvio < $mejor['desvio']) {
                $mejor = ['n' => $n, 'resto' => $resto, 'desvio' => $desvio];
            }
        }
        if ($mejor === null || $mejor['desvio'] >= $valorC - $unicoPiso) {
            return [[[$cant, $base]], $base];
        }

        return [[[(float) $mejor['n'], $costoAlto], [$mejor['resto'], $base]], $base];
    }

    /**
     * Centavos que vale un tramo (cantidad x costo) como [piso, techo]: iguales salvo cuando cae justo en medio centavo,
     * donde el redondeo puede ir a cualquiera de los dos lados. Cantidades y costos van a 4 decimales, así que el producto
     * en enteros es exacto (en cienmillonésimas de dólar).
     *
     * @return array{0:int,1:int}
     */
    private static function centavos(float $cant, float $costo): array
    {
        $c = (int) round($cant * 10000);
        $k = (int) round($costo * 10000);
        if ($k !== 0 && $c > intdiv(PHP_INT_MAX, $k)) {                           // cantidades descomunales: ya no cabe en un entero
            $n = (int) round($cant * $costo * 100);

            return [$n, $n];
        }
        $p = $c * $k;
        $piso = intdiv($p, 1000000);

        return $p % 1000000 === 500000 ? [$piso, $piso + 1] : [intdiv($p + 500000, 1000000), intdiv($p + 500000, 1000000)];
    }

    /** 12, 2.5, 0.0625: sin ceros de sobra. */
    private static function cantidad(float $n): string
    {
        return rtrim(rtrim(number_format($n, RegisterInventoryMovement::DEC_CANTIDAD, '.', ''), '0'), '.');
    }
}
