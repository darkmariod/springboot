<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Support\ComprobantesCompra;
use App\Support\CostosInventario;
use App\Support\Sustentos;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Reportes de ventas y compras (distinto de ReportController, que es solo
 * inventario). Cada método sirve JSON por defecto, o CSV/PDF con
 * ?formato=excel|pdf — misma data, tres formas de entregarla.
 */
class ReportesController extends Controller
{
    use ExportsReports;

    private function conRango(Request $r, $query, string $campo = 'fecha_emision')
    {
        if ($r->filled('desde')) $query->whereDate($campo, '>=', $r->desde);
        if ($r->filled('hasta')) $query->whereDate($campo, '<=', $r->hasta);
        return $query;
    }

    /** Separa las líneas de una factura/compra entre base 15% y base 0%, según la tarifa de cada ítem. */
    private function basesPorTarifa(?array $items): array
    {
        $b15 = 0.0;
        $b0 = 0.0;
        foreach ($items ?? [] as $it) {
            $sub = (float) ($it['cantidad'] ?? 0) * (float) ($it['precio_unitario'] ?? 0);
            if ((float) ($it['tarifa'] ?? 0) > 0) {
                $b15 += $sub;
            } else {
                $b0 += $sub;
            }
        }
        return [round($b15, 2), round($b0, 2)];
    }

    /**
     * Comprobantes emitidos: facturas, notas de crédito y notas de débito (SRI e internas). Las notas de crédito viven en su
     * propia tabla (credit_notes), las facturas y las notas de débito en `invoices`: cada tipo se lee de la suya.
     * El filtro "Nota de crédito" antes miraba `invoices` (siempre vacía). Columnas: tipo, número, cliente, fecha, factura
     * afectada (notas), SRI/Interna, estado y total.
     */
    public function comprobantes(Request $r)
    {
        $r->validate(['company_id' => ['required', 'exists:companies,id']]);

        $tipo = in_array($r->input('tipo'), ['factura', 'nota_credito', 'nota_debito'], true) ? $r->input('tipo') : null;
        $items = collect();

        if ($tipo !== 'nota_credito') {
            $q = Invoice::with(['contact:id,razon_social,identificacion', 'sriDocument:id,documentable_id,documentable_type,estado'])
                ->where('company_id', $r->company_id)
                ->where(fn ($sub) => match ($tipo) {
                    'factura' => $sub->whereNull('tipo_comprobante')->orWhere('tipo_comprobante', 'factura'),
                    'nota_debito' => $sub->whereIn('tipo_comprobante', Invoice::TIPOS_NOTA_DEBITO),
                    default => $sub->whereNull('tipo_comprobante')->orWhereIn('tipo_comprobante', array_merge(['factura'], Invoice::TIPOS_NOTA_DEBITO)),
                });
            $this->conRango($r, $q);
            foreach ($q->get() as $f) {
                $esNota = in_array($f->tipo_comprobante, Invoice::TIPOS_NOTA_DEBITO, true);
                $interna = $f->tipo_comprobante === 'nota_debito_interna';
                // El ciclo de la factura (emitida/anulado) y el del SRI (generado…autorizado) son relaciones distintas;
                // lo que el cliente entiende como "estado" es el del SRI, salvo que ya se haya anulado — ahí eso manda.
                $items->push([
                    'tipo' => $esNota ? 'Nota de débito' : 'Factura',
                    'numero' => $f->numero,
                    'cliente' => $f->contact?->razon_social ?? '—',
                    'identificacion' => $f->contact?->identificacion ?? '—',
                    'fecha_emision' => optional($f->fecha_emision)->format('Y-m-d'),
                    'factura_afectada' => $esNota ? ($f->numero_referencia ?: '—') : '—',
                    'origen' => $interna ? 'Interna' : 'SRI',
                    'estado' => $f->estado === 'anulado' ? 'anulado' : ($interna ? 'emitida' : ($f->sriDocument ? $f->estadoSri() : 'pendiente')),
                    'total' => (float) $f->importe_total,
                    '_orden' => ($f->fecha_emision?->format('Y-m-d H:i:s') ?? '').sprintf('-%010d', $f->id),
                ]);
            }
        }

        if (! $tipo || $tipo === 'nota_credito') {
            $q = CreditNote::with(['contact:id,razon_social,identificacion', 'invoice:id,numero', 'sriDocument:id,documentable_id,documentable_type,estado'])
                ->where('company_id', $r->company_id);
            $this->conRango($r, $q, 'fecha');
            foreach ($q->get() as $n) {
                // Una nota anulada pierde su tipo ('anulado'): si era interna se reconoce por su número NCI-
                $interna = $n->tipo === 'interna' || str_starts_with((string) $n->numero, 'NCI-');
                $items->push([
                    'tipo' => 'Nota de crédito',
                    'numero' => $n->numero ?: '—',
                    'cliente' => $n->contact?->razon_social ?? '—',
                    'identificacion' => $n->contact?->identificacion ?? '—',
                    'fecha_emision' => optional($n->fecha)->format('Y-m-d'),
                    'factura_afectada' => $n->invoice?->numero ?? '—',
                    'origen' => $interna ? 'Interna' : 'SRI',
                    'estado' => $n->tipo === 'anulado' ? 'anulado' : ($interna ? 'emitida' : ($n->sriDocument ? strtolower(trim((string) $n->sriDocument->estado)) : 'pendiente')),
                    'total' => (float) $n->importe_total,
                    '_orden' => ($n->fecha?->format('Y-m-d') ?? '').' 00:00:00'.sprintf('-%010d', $n->id),
                ]);
            }
        }

        $items = $items->sortByDesc('_orden')->values()->map(fn ($x) => Arr::except($x, '_orden'));

        if ($r->input('formato') === 'json' || ! $r->filled('formato')) {
            return response()->json(['items' => $items]);
        }

        $headers = ['Tipo', 'No.', 'Cliente', 'Identificación', 'Fecha Emisión', 'Factura afectada', 'SRI/Interna', 'Estado', 'Total'];
        $rows = $items->map(fn ($x) => [
            $x['tipo'], $x['numero'], $x['cliente'], $x['identificacion'], $x['fecha_emision'], $x['factura_afectada'],
            $x['origen'], $this->estadoVisible($x['estado']), number_format($x['total'], 2),
        ])->all();

        return $r->formato === 'pdf'
            ? $this->pdfResponse('Reporte de Comprobantes', $headers, $rows, 'reporte-comprobantes')
            : $this->csvResponse($headers, $rows, 'reporte-comprobantes');
    }

    /**
     * Ventas por factura, con sus bases por tarifa de IVA calculadas LÍNEA por línea (ver desgloseVenta) y el estado SRI.
     * Cuenta toda factura vigente (no anulada, no rechazada por el SRI), con o sin certificado de firma; `solo_autorizadas=1`
     * deja solo las que el SRI autorizó. Las notas de débito y de crédito no son ventas: quedan fuera de los totales.
     */
    public function ventas(Request $r)
    {
        $r->validate(['company_id' => ['required', 'exists:companies,id']]);

        $q = Invoice::ventasVigentes($r->boolean('solo_autorizadas'))
            ->with('contact:id,razon_social,identificacion', 'sriDocument:id,documentable_id,documentable_type,estado')
            ->where('company_id', $r->company_id)
            ->latest('fecha_emision');
        $this->conRango($r, $q);

        $resumen = [];
        $items = $q->get()->map(function ($f) use (&$resumen) {
            ['bases' => $bases, 'ivas' => $ivas] = $this->desgloseVenta($f);
            foreach ($bases as $cat => $base) {
                $resumen[$cat] ??= ['codigo' => (string) $cat, 'etiqueta' => $this->etiquetaTarifa((string) $cat, true), 'base' => 0.0, 'iva' => 0.0, 'facturas' => 0];
                $resumen[$cat]['base'] += $base;
                $resumen[$cat]['iva'] += $ivas[$cat] ?? 0;
                $resumen[$cat]['facturas']++;
            }
            $otras = 0.0;
            foreach ($bases as $cat => $base) {
                if (! in_array((string) $cat, ['15', '0', 'no_objeto', 'exento'], true)) {
                    $otras += $base;
                }
            }

            return [
                'numero' => $f->numero,
                'cliente' => $f->contact?->razon_social ?? '—',
                'identificacion' => $f->contact?->identificacion ?? '—',
                'fecha_emision' => optional($f->fecha_emision)->format('Y-m-d'),
                'estado_sri' => $f->estadoSri(),
                'subtotal_15' => $bases['15'] ?? 0.0,
                'subtotal_otras' => round($otras, 2),
                'subtotal_0' => $bases['0'] ?? 0.0,
                'no_objeto' => $bases['no_objeto'] ?? 0.0,
                'exento' => $bases['exento'] ?? 0.0,
                'iva' => (float) $f->total_impuesto,
                'total' => (float) $f->importe_total,
            ];
        });

        $columnas = ['subtotal_15', 'subtotal_otras', 'subtotal_0', 'no_objeto', 'exento', 'iva', 'total'];
        $totales = $this->sumarColumnas($items, $columnas);

        if (! $r->filled('formato')) {
            return response()->json([
                'items' => $items,
                'totales' => $totales,
                'por_tarifa' => $this->ordenarResumenTarifas($resumen),
                'solo_autorizadas' => $r->boolean('solo_autorizadas'),
            ]);
        }

        $dinero = fn ($n) => number_format((float) $n, 2);
        $headers = ['No.', 'Cliente', 'Identificación', 'Fecha Emisión', 'Estado SRI', 'Subtotal 15%', 'Otras tarifas', 'Subtotal 0%', 'No objeto IVA', 'Exento', 'IVA', 'Total'];
        $rows = $items->map(fn ($x) => [
            $x['numero'], $x['cliente'], $x['identificacion'], $x['fecha_emision'], $this->estadoVisible($x['estado_sri']),
            $dinero($x['subtotal_15']), $dinero($x['subtotal_otras']), $dinero($x['subtotal_0']),
            $dinero($x['no_objeto']), $dinero($x['exento']), $dinero($x['iva']), $dinero($x['total']),
        ])->all();
        $totalRow = ['', '', '', 'TOTAL', '', $dinero($totales['subtotal_15']), $dinero($totales['subtotal_otras']), $dinero($totales['subtotal_0']),
            $dinero($totales['no_objeto']), $dinero($totales['exento']), $dinero($totales['iva']), $dinero($totales['total'])];

        return $r->formato === 'pdf'
            ? $this->pdfResponse('Reporte de Ventas', $headers, $rows, 'reporte-ventas', $totalRow)
            : $this->csvResponse($headers, $rows, 'reporte-ventas');
    }

    public function ventasDetalle(Request $r)
    {
        $r->validate(['company_id' => ['required', 'exists:companies,id']]);

        $q = Invoice::ventasVigentes($r->boolean('solo_autorizadas'))
            ->where('company_id', $r->company_id)
            ->with('contact:id,razon_social', 'sriDocument:id,documentable_id,documentable_type,estado')
            ->latest('fecha_emision');
        $this->conRango($r, $q);

        $items = collect();
        foreach ($q->get() as $f) {
            foreach ($f->items ?? [] as $it) {
                if (! is_array($it)) {
                    continue;
                }
                $cat = $this->categoriaIva($it);
                $items->push([
                    'numero' => $f->numero,
                    'cliente' => $f->contact?->razon_social ?? '—',
                    'estado_sri' => $f->estadoSri(),
                    'producto' => trim(($it['codigo_principal'] ?? '') . ' ' . ($it['descripcion'] ?? '')),
                    'cantidad' => (float) ($it['cantidad'] ?? 0),
                    'precio_unitario' => (float) ($it['precio_unitario'] ?? 0),
                    'descuento' => (float) ($it['descuento'] ?? 0),
                    'tarifa' => (float) ($it['tarifa'] ?? 15),
                    'categoria' => $this->etiquetaTarifa($cat),
                    // Neto de descuento, igual que la base que lleva la factura
                    'subtotal' => $this->baseLinea($it),
                ]);
            }
        }

        if (! $r->filled('formato')) {
            return response()->json(['items' => $items, 'solo_autorizadas' => $r->boolean('solo_autorizadas')]);
        }

        $headers = ['No. Factura', 'Cliente', 'Estado SRI', 'Producto', 'Cant.', 'P. Unit.', 'Descuento', 'IVA', 'Subtotal'];
        $rows = $items->map(fn ($x) => [
            $x['numero'], $x['cliente'], $this->estadoVisible($x['estado_sri']), $x['producto'], $x['cantidad'],
            number_format($x['precio_unitario'], 2), number_format($x['descuento'], 2), $x['categoria'], number_format($x['subtotal'], 2),
        ])->all();

        return $r->formato === 'pdf'
            ? $this->pdfResponse('Ventas Detallada', $headers, $rows, 'ventas-detallada')
            : $this->csvResponse($headers, $rows, 'ventas-detallada');
    }

    // ------------------------------------------------------------ tarifas de IVA de las ventas, por línea

    /**
     * Categoría de IVA de una línea de venta: la tarifa real ('15', '5', '12'…), '0', 'no_objeto' (código de porcentaje 6) o
     * 'exento' (código 7). Una línea sin tarifa cuenta 15%: así la calcula la factura (DocumentCalculator) y el punto de venta.
     */
    private function categoriaIva(array $it): string
    {
        $codigo = (string) ($it['codigo_porcentaje'] ?? '');
        if ($codigo === '6') {
            return 'no_objeto';
        }
        if ($codigo === '7') {
            return 'exento';
        }
        $tarifa = (float) ($it['tarifa'] ?? 15);

        return $tarifa > 0 ? rtrim(rtrim(number_format($tarifa, 2, '.', ''), '0'), '.') : '0';
    }

    /** Base de una línea: cantidad × precio − descuento (lo mismo que suma la factura en su total sin impuestos). */
    private function baseLinea(array $it): float
    {
        return round((float) ($it['cantidad'] ?? 0) * (float) ($it['precio_unitario'] ?? 0) - (float) ($it['descuento'] ?? 0), 2);
    }

    /** '15%', '0%', 'No objeto de IVA', 'Exento de IVA'; con $conIva las tarifas llevan el prefijo: 'IVA 15%'. */
    private function etiquetaTarifa(string $cat, bool $conIva = false): string
    {
        return match ($cat) {
            'no_objeto' => 'No objeto de IVA',
            'exento' => 'Exento de IVA',
            default => ($conIva ? 'IVA ' : '').$cat.'%',
        };
    }

    /**
     * Bases e IVA de una factura por categoría de IVA, línea por línea. Una factura vieja sin líneas guardadas usa su total
     * sin impuestos: al 15% si cobró IVA, al 0% si no.
     *
     * @return array{bases: array<string, float>, ivas: array<string, float>}
     */
    private function desgloseVenta(Invoice $f): array
    {
        $bases = [];
        $ivas = [];
        foreach ($f->items ?? [] as $it) {
            if (! is_array($it)) {
                continue;
            }
            $cat = $this->categoriaIva($it);
            $base = $this->baseLinea($it);
            $bases[$cat] = ($bases[$cat] ?? 0.0) + $base;
            // Solo las tarifas positivas cobran IVA: 0%, no objeto y exento no
            $ivas[$cat] = ($ivas[$cat] ?? 0.0) + (is_numeric($cat) && (float) $cat > 0 ? round($base * (float) $cat / 100, 2) : 0.0);
        }
        if (! $bases && (float) $f->total_sin_impuestos != 0.0) {
            $cat = (float) $f->total_impuesto > 0 ? '15' : '0';
            $bases[$cat] = (float) $f->total_sin_impuestos;
            $ivas[$cat] = (float) $f->total_impuesto;
        }

        return ['bases' => array_map(fn ($v) => round($v, 2), $bases), 'ivas' => array_map(fn ($v) => round($v, 2), $ivas)];
    }

    /** Resumen por tarifa: de la mayor tarifa a la menor, luego 0%, No objeto y Exento; solo las que existen. */
    private function ordenarResumenTarifas(array $resumen): array
    {
        $peso = fn (string $cod) => match ($cod) { 'no_objeto' => -2.0, 'exento' => -3.0, '0' => -1.0, default => (float) $cod };
        $filas = array_map(fn ($f) => ['base' => round($f['base'], 2), 'iva' => round($f['iva'], 2)] + $f, array_values($resumen));
        usort($filas, fn ($a, $b) => $peso($b['codigo']) <=> $peso($a['codigo']));

        return $filas;
    }

    /** Estado para mostrar en Excel y PDF: 'generado' → 'Generado', 'sin sri' → 'Sin SRI'. */
    private function estadoVisible(string $estado): string
    {
        return $estado === 'sin sri' ? 'Sin SRI' : ucfirst($estado);
    }

    public function compras(Request $r)
    {
        $r->validate(['company_id' => ['required', 'exists:companies,id']]);

        $q = Purchase::with('contact:id,razon_social,identificacion')
            ->where('company_id', $r->company_id)
            ->when($r->filled('contact_id'), fn ($qq) => $qq->where('contact_id', $r->contact_id))
            ->latest('fecha_emision');
        $this->conRango($r, $q);

        $items = $q->get()->map(function ($p) {
            [$b15, $b0] = $this->basesPorTarifa($p->items);
            return [
                'numero' => $p->numero,
                'proveedor' => $p->contact?->razon_social ?? '—',
                'ruc' => $p->contact?->identificacion ?? '—',
                'fecha_emision' => optional($p->fecha_emision)->format('Y-m-d'),
                'subtotal_15' => $b15,
                'subtotal_0' => $b0,
                'iva' => (float) $p->total_impuesto,
                'total' => (float) $p->importe_total,
                // Una compra manual (sin clave de acceso) es una factura física del proveedor.
                'factura_fisica' => $p->clave_acceso ? 'No' : 'Sí',
            ];
        });

        if (! $r->filled('formato')) {
            return response()->json([
                'items' => $items,
                'totales' => $this->sumarColumnas($items, ['subtotal_15', 'subtotal_0', 'iva', 'total']),
            ]);
        }

        $headers = ['No.', 'Proveedor', 'RUC Proveedor', 'Fecha Emisión', 'Subtotal 15%', 'Subtotal 0%', 'IVA', 'Total', 'Factura Física'];
        $rows = $items->map(fn ($x) => [
            $x['numero'], $x['proveedor'], $x['ruc'], $x['fecha_emision'],
            number_format($x['subtotal_15'], 2), number_format($x['subtotal_0'], 2),
            number_format($x['iva'], 2), number_format($x['total'], 2), $x['factura_fisica'],
        ])->all();

        return $r->formato === 'pdf'
            ? $this->pdfResponse('Reporte de Compras', $headers, $rows, 'reporte-compras')
            : $this->csvResponse($headers, $rows, 'reporte-compras');
    }

    /**
     * Compras por sustento tributario: una sección por sustento (código y nombre) y otra por
     * tipo de comprobante, con la cantidad de comprobantes, los subtotales, el IVA y el total,
     * más el total general. Cada compra entra una vez en cada sección, así que ambas suman lo mismo.
     */
    public function comprasPorSustento(Request $r)
    {
        $r->validate(['company_id' => ['required', 'exists:companies,id']]);

        $q = Purchase::where('company_id', $r->company_id)
            ->when($r->filled('contact_id'), fn ($qq) => $qq->where('contact_id', $r->contact_id));
        $this->conRango($r, $q);

        $porSustento = [];
        $porTipo = [];
        $general = $this->filaAcumulada();
        foreach ($q->get() as $p) {
            [$b15, $b0] = $this->basesNetasCompra($p);
            $valores = [
                'subtotal_15' => $b15,
                'subtotal_0' => $b0,
                'iva' => (float) $p->total_impuesto,
                'total' => (float) $p->importe_total,
            ];

            $sustento = (string) ($p->sustento_tributario ?? '');
            $nombre = $sustento === '' ? 'Sin sustento asignado' : Sustentos::nombre($sustento);
            $porSustento[$sustento] ??= $this->filaAcumulada($sustento, $nombre);
            $this->acumular($porSustento[$sustento], $valores);

            $tipo = ComprobantesCompra::codigo($p->tipo_comprobante);
            $porTipo[$tipo] ??= $this->filaAcumulada($tipo, ComprobantesCompra::nombre($tipo));
            $this->acumular($porTipo[$tipo], $valores);

            $this->acumular($general, $valores);
        }
        ksort($porSustento, SORT_STRING);
        ksort($porTipo, SORT_STRING);
        $porSustento = array_map(fn ($f) => $this->redondear($f), array_values($porSustento));
        $porTipo = array_map(fn ($f) => $this->redondear($f), array_values($porTipo));
        $general = array_diff_key($this->redondear($general), ['codigo' => 1, 'nombre' => 1]);

        if (! $r->filled('formato')) {
            return response()->json(['por_sustento' => $porSustento, 'por_tipo' => $porTipo, 'totales' => $general]);
        }

        $dinero = fn ($n) => number_format((float) $n, 2, '.', '');
        $fila = fn (array $f) => [$f['codigo'], $f['nombre'], $f['comprobantes'],
            $dinero($f['subtotal_15']), $dinero($f['subtotal_0']), $dinero($f['iva']), $dinero($f['total'])];
        $headers = ['Código', 'Descripción', 'Comprobantes', 'Subtotal 15%', 'Subtotal 0%', 'IVA', 'Total'];
        $rows = array_merge(
            ['Por sustento tributario'], array_map($fila, $porSustento),
            ['Por tipo de comprobante'], array_map($fila, $porTipo),
        );
        $totalRow = ['', 'TOTAL GENERAL', $general['comprobantes'],
            $dinero($general['subtotal_15']), $dinero($general['subtotal_0']), $dinero($general['iva']), $dinero($general['total'])];

        return $r->formato === 'pdf'
            ? $this->pdfResponse('Compras por sustento tributario', $headers, $rows, 'reporte-compras-sustento', $totalRow)
            : $this->csvResponse($headers, array_merge($rows, [$totalRow]), 'reporte-compras-sustento');
    }

    /**
     * Bases de una compra al 15% y al 0%, netas de descuento (lo mismo que entra al asiento).
     * Si las líneas no suman la base de la compra (sin líneas, o centavos de redondeo), la
     * diferencia va al grupo que corresponde según la compra cobre IVA o no.
     */
    private function basesNetasCompra(Purchase $p): array
    {
        $b15 = 0.0;
        $b0 = 0.0;
        foreach ($p->items ?? [] as $it) {
            $base = CostosInventario::baseCompra($it);
            if ((float) ($it['tarifa'] ?? 0) > 0) {
                $b15 += $base;
            } else {
                $b0 += $base;
            }
        }
        $dif = round((float) $p->total_sin_impuestos - ($b15 + $b0), 2);
        if (abs($dif) >= 0.01) {
            if ((float) $p->total_impuesto > 0) {
                $b15 += $dif;
            } else {
                $b0 += $dif;
            }
        }

        return [round($b15, 2), round($b0, 2)];
    }

    private function filaAcumulada(string $codigo = '', string $nombre = ''): array
    {
        return ['codigo' => $codigo, 'nombre' => $nombre, 'comprobantes' => 0,
            'subtotal_15' => 0.0, 'subtotal_0' => 0.0, 'iva' => 0.0, 'total' => 0.0];
    }

    private function acumular(array &$fila, array $valores): void
    {
        $fila['comprobantes']++;
        foreach ($valores as $k => $v) {
            $fila[$k] += $v;
        }
    }

    private function redondear(array $fila): array
    {
        foreach (['subtotal_15', 'subtotal_0', 'iva', 'total'] as $k) {
            $fila[$k] = round($fila[$k], 2);
        }

        return $fila;
    }

    private function sumarColumnas(Collection $items, array $columnas): array
    {
        $out = [];
        foreach ($columnas as $c) {
            $out[$c] = round($items->sum($c), 2);
        }
        return $out;
    }
}
