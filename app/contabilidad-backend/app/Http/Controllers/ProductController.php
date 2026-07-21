<?php
namespace App\Http\Controllers;
use App\Models\Product;
use Illuminate\Http\Request;
class ProductController extends Controller {
    public function index(Request $r) {
        return Product::when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->orderBy('descripcion')->get();
    }
    public function store(Request $r) {
        $data = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'codigo'=>['required','string','max:50'],
            'descripcion'=>['required','string','max:255'],
            'tipo'=>['sometimes','in:bien,servicio'],
            'imagen'=>['nullable','string','max:1000'],
            'precio'=>['required','numeric','min:0'],
            'tarifa_iva'=>['sometimes','numeric','min:0'],
            'maneja_series'=>['boolean'],
            'es_combo'=>['boolean'],
            'stock_minimo'=>['nullable','numeric','min:0'],
            'stock_maximo'=>['nullable','numeric','min:0'],
            'ubicacion'=>['nullable','string'],
            'unidad_base'=>['nullable','string','max:20'],
            'unidad_fraccion'=>['nullable','string','max:20'],
            'factor_conversion'=>['nullable','numeric','min:0'],
            'reserva_stock'=>['nullable','numeric','min:0'],
        ]);
        return response()->json(Product::create($data), 201);
    }
    public function update(Request $r, Product $product) { $product->update($r->all()); return $product; }
    public function destroy(Product $product) { $product->delete(); return response()->noContent(); }
    public function lookup(Request $r) {
        $codigo = trim($r->codigo ?? '');
        $p = Product::where('company_id', $r->company_id)
            ->where(fn($q) => $q->where('codigo', $codigo)
                ->orWhereHas('codes', fn($c) => $c->where('codigo', $codigo)))
            ->first();
        return $p ?: response()->json(['message' => 'Producto no encontrado'], 404);
    }
}
