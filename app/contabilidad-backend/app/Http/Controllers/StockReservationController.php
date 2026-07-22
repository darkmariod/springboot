<?php
namespace App\Http\Controllers;

use App\Models\StockReservation;
use App\Models\Product;
use Illuminate\Http\Request;

class StockReservationController extends Controller
{
    public function index(Request $r)
    {
        $r->validate(['company_id' => ['required', 'exists:companies,id']]);

        return StockReservation::where('company_id', $r->company_id)
            ->with('product:codigo,descripcion')
            ->with('contact:razon_social')
            ->orderByDesc('created_at')
            ->get();
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'company_id'  => ['required', 'exists:companies,id'],
            'product_id'  => ['required', 'exists:products,id'],
            'contact_id'  => ['nullable', 'exists:contacts,id'],
            'quantity'    => ['required', 'numeric', 'min:0.01'],
            'motivo'      => ['nullable', 'string', 'max:255'],
            'expires_at'  => ['nullable', 'date'],
        ]);

        $product = Product::findOrFail($d['product_id']);
        $reservado = StockReservation::where('product_id', $d['product_id'])
            ->where('estado', 'activa')
            ->sum('quantity');
        $disponible = (float) $product->stock - (float) $reservado;

        if ($disponible < $d['quantity']) {
            return response()->json([
                'error' => "Stock insuficiente. Disponible para reserva: {$disponible} {$product->unidad_base}",
            ], 422);
        }

        $reservation = StockReservation::create($d);

        return response()->json($reservation->load('product:codigo,descripcion', 'contact:razon_social'));
    }

    public function cancel(StockReservation $reservation)
    {
        if ($reservation->estado !== 'activa') {
            return response()->json(['error' => 'La reserva no está activa.'], 422);
        }

        $reservation->update(['estado' => 'cancelada']);

        return response()->json(['ok' => true]);
    }
}
