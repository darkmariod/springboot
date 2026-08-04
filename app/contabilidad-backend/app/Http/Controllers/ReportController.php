<?php
namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function stockReport(Request $r)
    {
        $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
        ]);

        $query = Product::where('company_id', $r->company_id)
            ->where('tipo', '!=', 'servicio')
            ->with('priceLists');

        if ($r->filled('warehouse_id')) {
            $query->whereHas('warehouseStocks', function ($q) use ($r) {
                $q->where('warehouse_id', $r->warehouse_id);
            });
        }

        if ($r->filled('category')) {
            $query->where('tipo', $r->category);
        }

        if ($r->filled('producto')) {
            $q = $r->producto;
            $query->where(function ($sub) use ($q) {
                $sub->where('codigo', 'like', "%{$q}%")
                    ->orWhere('descripcion', 'like', "%{$q}%");
            });
        }

        $orderBy = $r->input('orden', 'codigo');
        $orderMap = [
            'codigo' => 'codigo',
            'descripcion' => 'descripcion',
            'stock' => 'stock',
        ];
        $query->orderBy($orderMap[$orderBy] ?? 'codigo');

        $products = $query->get();

        $items = $products->map(function ($p) {
            $lastMovement = InventoryMovement::where('product_id', $p->id)
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->first();

            return [
                'codigo' => $p->codigo,
                'descripcion' => $p->descripcion,
                'unidad' => $p->unidad_base ?? 'UND',
                'stock_actual' => (float) $p->stock,
                'costo_promedio' => (float) $p->costo_promedio,
                'valor_total' => round((float) $p->stock * (float) $p->costo_promedio, 2),
                'ultimo_movimiento_fecha' => $lastMovement?->fecha,
            ];
        });

        return response()->json([
            'items' => $items,
            'valor_total' => round($items->sum('valor_total'), 2),
        ]);
    }

    public function kardexReport(Request $r, Product $product)
    {
        $movimientos = InventoryMovement::where('product_id', $product->id)
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        return response()->json([
            'producto' => [
                'id' => $product->id,
                'codigo' => $product->codigo,
                'descripcion' => $product->descripcion,
                'stock' => (float) $product->stock,
                'costo_promedio' => (float) $product->costo_promedio,
            ],
            'movimientos' => $movimientos,
        ]);
    }

    /** Reporte de series por artículo (detalle) + resumen por producto. */
    public function seriesReport(Request $r)
    {
        $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
        ]);

        $query = \App\Models\ProductSerie::query()
            ->where('company_id', $r->company_id)
            ->with(['product:id,codigo,descripcion,maneja_series', 'invoice:id,numero,fecha_emision,estado']);

        if ($r->filled('producto')) {
            $q = $r->producto;
            $query->whereHas('product', function ($sub) use ($q) {
                $sub->where('codigo', 'like', "%{$q}%")
                    ->orWhere('descripcion', 'like', "%{$q}%");
            });
        }

        if ($r->filled('estado')) {
            $query->where('estado', $r->estado);
        }

        $series = $query->orderBy('product_id')->orderBy('serie')->get();

        $items = $series->map(function ($s) {
            return [
                'id' => $s->id,
                'codigo' => $s->product?->codigo ?? '—',
                'descripcion' => $s->product?->descripcion ?? '—',
                'serie' => $s->serie,
                'estado' => $s->estado,
                'factura' => $s->invoice?->numero ?? null,
                'fecha_venta' => $s->invoice?->fecha_emision ?? null,
            ];
        });

        $resumen = $series->groupBy('product_id')->map(function ($grupo) {
            $p = $grupo->first()->product;
            $disponibles = $grupo->where('estado', 'disponible')->count();
            $vendidas = $grupo->where('estado', 'vendido')->count();
            return [
                'codigo' => $p->codigo ?? '—',
                'descripcion' => $p->descripcion ?? '—',
                'disponibles' => $disponibles,
                'vendidas' => $vendidas,
                'total' => $grupo->count(),
            ];
        })->values();

        return response()->json([
            'items' => $items,
            'resumen' => $resumen,
            'totales' => [
                'disponibles' => $series->where('estado', 'disponible')->count(),
                'vendidas' => $series->where('estado', 'vendido')->count(),
                'total' => $series->count(),
            ],
        ]);
    }

    /** Exportar CSV del reporte de series. */
    public function exportSeriesCsv(Request $r)
    {
        $data = $this->seriesReport($r)->original;

        $header = ['Código', 'Descripción', 'Serie', 'Estado', 'Factura', 'Fecha Venta'];
        $csv = $header . "\n";
        foreach ($data['items'] as $item) {
            $csv .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', [
                $item['codigo'],
                $item['descripcion'],
                $item['serie'],
                $item['estado'],
                $item['factura'] ?? '',
                $item['fecha_venta'] ?? '',
            ])) . "\n";
        }

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="reporte-series.csv"');
    }

    public function generatePdf(Request $r)
    {
        $r->validate([
            'tipo' => ['required', 'in:existencias'],
            'company_id' => ['required', 'exists:companies,id'],
        ]);

        $stockData = $this->getStockData($r);

        $html = $this->buildStockHtml($stockData, $r->hasta ?? now()->format('Y-m-d'));

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHtml($html)
            ->setPaper('letter', 'landscape')
            ->setOption('isRemoteEnabled', true);

        return $pdf->download('reporte-existencias.pdf');
    }

    public function exportCsv(Request $r)
    {
        $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
        ]);

        $stockData = $this->getStockData($r);

        $header = ['Código', 'Descripción', 'Unidad', 'Stock Actual', 'Costo Promedio', 'Valor Total'];
        $rows = $stockData['items']->map(function ($item) {
            return [
                $item['codigo'],
                $item['descripcion'],
                $item['unidad'],
                $item['stock_actual'],
                $item['costo_promedio'],
                $item['valor_total'],
            ];
        });

        $csv = $header . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $row)) . "\n";
        }

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="reporte-existencias.csv"');
    }

    private function getStockData(Request $r)
    {
        $query = Product::where('company_id', $r->company_id)
            ->where('tipo', '!=', 'servicio');

        if ($r->filled('warehouse_id')) {
            $query->whereHas('warehouseStocks', function ($q) use ($r) {
                $q->where('warehouse_id', $r->warehouse_id);
            });
        }

        if ($r->filled('category')) {
            $query->where('tipo', $r->category);
        }

        if ($r->filled('producto')) {
            $q = $r->producto;
            $query->where(function ($sub) use ($q) {
                $sub->where('codigo', 'like', "%{$q}%")
                    ->orWhere('descripcion', 'like', "%{$q}%");
            });
        }

        $orderBy = $r->input('orden', 'codigo');
        $orderMap = ['codigo' => 'codigo', 'descripcion' => 'descripcion', 'stock' => 'stock'];
        $query->orderBy($orderMap[$orderBy] ?? 'codigo');

        $products = $query->get();

        $items = $products->map(function ($p) {
            $lastMovement = InventoryMovement::where('product_id', $p->id)
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->first();

            return [
                'codigo' => $p->codigo,
                'descripcion' => $p->descripcion,
                'unidad' => $p->unidad_base ?? 'UND',
                'stock_actual' => (float) $p->stock,
                'costo_promedio' => (float) $p->costo_promedio,
                'valor_total' => round((float) $p->stock * (float) $p->costo_promedio, 2),
                'ultimo_movimiento_fecha' => $lastMovement?->fecha,
            ];
        });

        return [
            'items' => $items,
            'valor_total' => round($items->sum('valor_total'), 2),
        ];
    }

    private function buildStockHtml($data, $fecha)
    {
        $rows = '';
        foreach ($data['items'] as $item) {
            $rows .= "<tr>
                <td>{$item['codigo']}</td>
                <td>{$item['descripcion']}</td>
                <td>{$item['unidad']}</td>
                <td class='num'>{$item['stock_actual']}</td>
                <td class='num'>\$" . number_format($item['costo_promedio'], 2) . "</td>
                <td class='num'>\$" . number_format($item['valor_total'], 2) . "</td>
            </tr>";
        }

        return "<!DOCTYPE html>
<html>
<head>
<style>
    body { font-family: Arial, sans-serif; font-size: 10px; }
    h1 { text-align: center; font-size: 14px; margin-bottom: 4px; }
    h2 { text-align: center; font-size: 11px; color: #666; margin-top: 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { background: #1e5bb8; color: #fff; padding: 5px 8px; text-align: left; font-size: 9px; }
    td { padding: 4px 8px; border-bottom: 1px solid #ddd; font-size: 9px; }
    .num { text-align: right; }
    .total { font-weight: bold; border-top: 2px solid #333; }
</style>
</head>
<body>
    <h1>REPORTE DE EXISTENCIAS</h1>
    <h2>Fecha: {$fecha}</h2>
    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Descripción</th>
                <th>Unidad</th>
                <th style='text-align:right'>Stock Actual</th>
                <th style='text-align:right'>Costo Promedio</th>
                <th style='text-align:right'>Valor Total</th>
            </tr>
        </thead>
        <tbody>
            {$rows}
            <tr class='total'>
                <td colspan='3'>TOTAL</td>
                <td class='num'></td>
                <td class='num'></td>
                <td class='num'>\$" . number_format($data['valor_total'], 2) . "</td>
            </tr>
        </tbody>
    </table>
</body>
</html>";
    }
}
