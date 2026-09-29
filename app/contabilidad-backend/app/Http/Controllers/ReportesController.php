<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Models\Invoice;
use App\Models\Purchase;
use Illuminate\Http\Request;
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

    public function comprobantes(Request $r)
    {
        $r->validate(['company_id' => ['required', 'exists:companies,id']]);

        $tiposValidos = ['factura', 'nota_credito', 'nota_debito'];
        $tipo = $r->input('tipo');

        $q = Invoice::with(['contact:id,razon_social,identificacion', 'sriDocument:id,documentable_id,estado'])
            ->where('company_id', $r->company_id)
            ->when(
                $tipo && in_array($tipo, $tiposValidos, true),
                fn ($qq) => $qq->where('tipo_comprobante', $tipo),
                fn ($qq) => $qq->where(fn ($sub) => $sub->whereNull('tipo_comprobante')->orWhereIn('tipo_comprobante', $tiposValidos)),
            )
            ->latest('fecha_emision');
        $this->conRango($r, $q);

        // El ciclo de la factura (emitida/anulado) y el del SRI (generado…autorizado) son
        // relaciones distintas; lo que el cliente entiende como "estado" es el del SRI,
        // salvo que la factura ya se haya anulado — ahí eso manda sobre lo demás.
        $items = $q->get()->map(fn ($f) => [
            'numero' => $f->numero,
            'cliente' => $f->contact?->razon_social ?? '—',
            'identificacion' => $f->contact?->identificacion ?? '—',
            'fecha_emision' => optional($f->fecha_emision)->format('Y-m-d'),
            'estado' => $f->estado === 'anulado' ? 'anulado' : ($f->sriDocument?->estado ?? 'pendiente'),
            'total' => (float) $f->importe_total,
        ]);

        if ($r->input('formato') === 'json' || ! $r->filled('formato')) {
            return response()->json(['items' => $items]);
        }

        $headers = ['No.', 'Cliente', 'Identificación', 'Fecha Emisión', 'Estado', 'Total'];
        $rows = $items->map(fn ($x) => [$x['numero'], $x['cliente'], $x['identificacion'], $x['fecha_emision'], $x['estado'], number_format($x['total'], 2)])->all();

        return $r->formato === 'pdf'
            ? $this->pdfResponse('Reporte de Comprobantes', $headers, $rows, 'reporte-comprobantes')
            : $this->csvResponse($headers, $rows, 'reporte-comprobantes');
    }

    public function ventas(Request $r)
    {
        $r->validate(['company_id' => ['required', 'exists:companies,id']]);

        $q = Invoice::with('contact:id,razon_social,identificacion')
            ->where('company_id', $r->company_id)
            ->where('estado', '!=', 'anulado')
            ->whereHas('sriDocument', fn ($sd) => $sd->whereRaw('LOWER(estado) = ?', ['autorizado']))
            ->where(fn ($sub) => $sub->whereNull('tipo_comprobante')->orWhere('tipo_comprobante', 'factura'))
            ->latest('fecha_emision');
        $this->conRango($r, $q);

        $items = $q->get()->map(function ($f) {
            [$b15, $b0] = $this->basesPorTarifa($f->items);
            return [
                'numero' => $f->numero,
                'cliente' => $f->contact?->razon_social ?? '—',
                'identificacion' => $f->contact?->identificacion ?? '—',
                'fecha_emision' => optional($f->fecha_emision)->format('Y-m-d'),
                'subtotal_15' => $b15,
                'subtotal_0' => $b0,
                'iva' => (float) $f->total_impuesto,
                'total' => (float) $f->importe_total,
            ];
        });

        if (! $r->filled('formato')) {
            return response()->json([
                'items' => $items,
                'totales' => $this->sumarColumnas($items, ['subtotal_15', 'subtotal_0', 'iva', 'total']),
            ]);
        }

        $headers = ['No.', 'Cliente', 'Identificación', 'Fecha Emisión', 'Subtotal 15%', 'Subtotal 0%', 'IVA', 'Total'];
        $rows = $items->map(fn ($x) => [
            $x['numero'], $x['cliente'], $x['identificacion'], $x['fecha_emision'],
            number_format($x['subtotal_15'], 2), number_format($x['subtotal_0'], 2),
            number_format($x['iva'], 2), number_format($x['total'], 2),
        ])->all();
        $totales = $this->sumarColumnas($items, ['subtotal_15', 'subtotal_0', 'iva', 'total']);
        $totalRow = ['', '', '', 'TOTAL', number_format($totales['subtotal_15'], 2), number_format($totales['subtotal_0'], 2), number_format($totales['iva'], 2), number_format($totales['total'], 2)];

        return $r->formato === 'pdf'
            ? $this->pdfResponse('Reporte de Ventas', $headers, $rows, 'reporte-ventas', $totalRow)
            : $this->csvResponse($headers, $rows, 'reporte-ventas');
    }

    public function ventasDetalle(Request $r)
    {
        $r->validate(['company_id' => ['required', 'exists:companies,id']]);

        $q = Invoice::where('company_id', $r->company_id)
            ->where('estado', '!=', 'anulado')
            ->whereHas('sriDocument', fn ($sd) => $sd->whereRaw('LOWER(estado) = ?', ['autorizado']))
            ->where(fn ($sub) => $sub->whereNull('tipo_comprobante')->orWhere('tipo_comprobante', 'factura'))
            ->with('contact:id,razon_social')
            ->latest('fecha_emision');
        $this->conRango($r, $q);

        $items = collect();
        foreach ($q->get() as $f) {
            foreach ($f->items ?? [] as $it) {
                $cant = (float) ($it['cantidad'] ?? 0);
                $precio = (float) ($it['precio_unitario'] ?? 0);
                $items->push([
                    'numero' => $f->numero,
                    'cliente' => $f->contact?->razon_social ?? '—',
                    'producto' => trim(($it['codigo_principal'] ?? '') . ' ' . ($it['descripcion'] ?? '')),
                    'cantidad' => $cant,
                    'precio_unitario' => $precio,
                    'tarifa' => (float) ($it['tarifa'] ?? 0),
                    'subtotal' => round($cant * $precio, 2),
                ]);
            }
        }

        if (! $r->filled('formato')) {
            return response()->json(['items' => $items]);
        }

        $headers = ['No. Factura', 'Cliente', 'Producto', 'Cant.', 'P. Unit.', 'IVA', 'Subtotal'];
        $rows = $items->map(fn ($x) => [
            $x['numero'], $x['cliente'], $x['producto'], $x['cantidad'],
            number_format($x['precio_unitario'], 2), $x['tarifa'] . '%', number_format($x['subtotal'], 2),
        ])->all();

        return $r->formato === 'pdf'
            ? $this->pdfResponse('Ventas Detallada', $headers, $rows, 'ventas-detallada')
            : $this->csvResponse($headers, $rows, 'ventas-detallada');
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

    private function sumarColumnas(Collection $items, array $columnas): array
    {
        $out = [];
        foreach ($columnas as $c) {
            $out[$c] = round($items->sum($c), 2);
        }
        return $out;
    }
}
