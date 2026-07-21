<?php
namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Services\RegisterInventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ArticleConversionController extends Controller
{
    public function store(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'product_from_id' => ['required', 'exists:products,id'],
            'product_to_id' => ['required', 'exists:products,id', 'different:product_from_id'],
            'quantity_from' => ['required', 'numeric', 'min:0.01'],
            'quantity_to' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $productFrom = Product::findOrFail($d['product_from_id']);
        $productTo = Product::findOrFail($d['product_to_id']);

        if ((float)$productFrom->stock < $d['quantity_from']) {
            return response()->json([
                'error' => "Stock insuficiente en artículo origen. Disponible: {$productFrom->stock}",
            ], 422);
        }

        return DB::transaction(function () use ($d, $productFrom, $productTo) {
            $movOut = app(RegisterInventoryMovement::class)->handle(
                $productFrom,
                'egreso',
                $d['quantity_from'],
                (float)$productFrom->costo_promedio,
                'Conversión a ' . $productTo->codigo . ': ' . ($d['motivo'] ?? ''),
                now()->toDateString()
            );

            $movIn = app(RegisterInventoryMovement::class)->handle(
                $productTo,
                'ingreso',
                $d['quantity_to'],
                (float)$productFrom->costo_promedio,
                'Conversión desde ' . $productFrom->codigo . ': ' . ($d['motivo'] ?? ''),
                now()->toDateString()
            );

            $company = Company::findOrFail($d['company_id']);
            $num = sprintf('CONV-%s-%09d', $company->estab, $company->secuencial);
            $company->increment('secuencial');

            JournalEntry::create([
                'company_id' => $d['company_id'],
                'numero' => $num,
                'fecha' => now(),
                'concepto' => "Conversión: {$productFrom->codigo} → {$productTo->codigo}",
                'total_debe' => 0,
                'total_haber' => 0,
                'estado' => 'borrador',
            ]);

            return response()->json([
                'ok' => true,
                'producto_origen' => $productFrom->codigo,
                'producto_destino' => $productTo->codigo,
                'cantidad_origen' => $d['quantity_from'],
                'cantidad_destino' => $d['quantity_to'],
                'movimiento_salida' => $movOut->id,
                'movimiento_entrada' => $movIn->id,
            ]);
        });
    }
}
