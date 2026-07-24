<?php
namespace App\Http\Controllers;
use App\Models\ProductSerie;
use Illuminate\Http\Request;

class SerieController extends Controller {
    public function index(Request $r) {
        return ProductSerie::with('product:id,codigo,descripcion','purchase:id,numero','invoice:id,numero')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->when($r->product_id, fn($q,$id)=>$q->where('product_id',$id))
            ->when($r->estado, fn($q,$e)=>$q->where('estado',$e))
            ->orderBy('serie')->get();
    }
    public function store(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'product_id'=>['required','exists:products,id'],
            'purchase_id'=>['nullable','exists:purchases,id'],
            'series'=>['required','array','min:1'],
            'series.*'=>['required','string'],
        ]);
        $creadas = [];
        foreach ($d['series'] as $s) {
            $serie = trim($s);
            if ($serie === '') continue;
            $creadas[] = ProductSerie::firstOrCreate(
                ['company_id'=>$d['company_id'],'serie'=>$serie],
                ['product_id'=>$d['product_id'],'purchase_id'=>$d['purchase_id'] ?? null,'estado'=>'disponible']);
        }
        return response()->json($creadas, 201);
    }
    public function lookup(Request $r) {
        $s = ProductSerie::with('product')
            ->where('company_id',$r->company_id)->where('serie',trim($r->serie ?? ''))
            ->where('estado','disponible')->first();
        return $s ?: response()->json(['message'=>'Serie no encontrada o ya vendida'], 404);
    }
    public function update(Request $r, ProductSerie $serie) {
        $d = $r->validate([
            'product_id'=>['sometimes','exists:products,id'],
            'purchase_id'=>['nullable','exists:purchases,id'],
            'estado'=>['sometimes','in:disponible,vendido,devuelto,danado'],
        ]);
        $serie->update($d);
        return $serie->load('product:id,codigo,descripcion');
    }
    public function destroy(ProductSerie $serie) {
        if ($serie->estado === 'vendido') {
            return response()->json(['message'=>'No se puede eliminar una serie vendida.'], 422);
        }
        $serie->delete();
        return response()->noContent();
    }
    public function trace(Request $r) {
        $s = ProductSerie::with('product:id,codigo,descripcion',
                'purchase.contact:id,razon_social','invoice.contact:id,razon_social')
            ->where('company_id',$r->company_id)->where('serie',trim($r->serie ?? ''))->first();
        return $s ?: response()->json(['message'=>'Serie no encontrada'], 404);
    }
}
