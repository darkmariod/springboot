<?php
namespace App\Http\Controllers;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Purchase;
use App\Services\ParseSriPurchaseXml;
use App\Services\RegisterInventoryMovement;
use App\Services\GeneratePurchaseJournalEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PurchaseController extends Controller {
    public function index(Request $r) {
        return Purchase::with('contact:id,razon_social')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->latest('fecha_emision')->get();
    }
    public function import(Request $r, ParseSriPurchaseXml $parser, RegisterInventoryMovement $inv, GeneratePurchaseJournalEntry $asiento) {
        $r->validate(['company_id'=>['required','exists:companies,id'],'xml'=>['required','file','max:2048']]);
        $companyId = (int)$r->company_id;
        try { $d = $parser->parse(file_get_contents($r->file('xml')->getRealPath())); }
        catch (InvalidArgumentException $e) { throw ValidationException::withMessages(['xml'=>[$e->getMessage()]]); }

        if ($d['comprobante']['clave_acceso'] &&
            Purchase::where('company_id',$companyId)->where('clave_acceso',$d['comprobante']['clave_acceso'])->exists())
            throw ValidationException::withMessages(['xml'=>['Esta factura de compra ya fue importada.']]);

        $purchase = DB::transaction(function() use ($companyId,$d,$inv,$asiento) {
            $proveedor = Contact::firstOrCreate(
                ['company_id'=>$companyId,'identificacion'=>$d['proveedor']['identificacion']],
                $d['proveedor'] + ['company_id'=>$companyId,'es_proveedor'=>true,'es_cliente'=>false]);
            $purchase = Purchase::create([
                'company_id'=>$companyId,'contact_id'=>$proveedor->id,
                'numero'=>$d['comprobante']['numero'],'clave_acceso'=>$d['comprobante']['clave_acceso'],
                'fecha_emision'=>$d['comprobante']['fecha_emision'],'items'=>$d['items'],
                'total_sin_impuestos'=>$d['totales']['total_sin_impuestos'],'total_impuesto'=>$d['totales']['total_impuesto'],
                'importe_total'=>$d['totales']['importe_total'],'saldo_pendiente'=>$d['totales']['importe_total'],'xml'=>$d['xml'],
            ]);
            // Inventario: cada item con código ingresa stock (Fase 4)
            foreach ($d['items'] as $item) {
                $codigo = trim((string)($item['codigo_principal'] ?? ''));
                $cant = (float)($item['cantidad'] ?? 0);
                if ($codigo==='' || $cant<=0) continue;
                $product = Product::firstOrCreate(
                    ['company_id'=>$companyId,'codigo'=>$codigo],
                    ['descripcion'=>$item['descripcion'] ?? $codigo,'tipo'=>'bien','precio'=>$item['precio_unitario'] ?? 0,'tarifa_iva'=>$item['tarifa'] ?? 15]);
                if ($product->tipo !== 'servicio')
                    $inv->handle($product,'ingreso',$cant,(float)($item['precio_unitario'] ?? 0),'Compra '.$purchase->numero,$purchase->fecha_emision->toDateString());
            }
            // Asiento contable (Fase 6)
            $asiento->handle($purchase);
            return $purchase;
        });
        return response()->json($purchase->load('contact'), 201);
    }
}
