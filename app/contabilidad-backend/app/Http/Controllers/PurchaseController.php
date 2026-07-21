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

    /** Registro manual de compra (sin XML). */
    public function store(Request $r, RegisterInventoryMovement $inv, GeneratePurchaseJournalEntry $asiento) {
        $r->validate([
            'company_id' => ['required','exists:companies,id'],
            'contact_id' => ['required','exists:contacts,id'],
            'numero' => ['required','string'],
            'fecha_emision' => ['required','date'],
            'items' => ['required','array','min:1'],
            'items.*.codigo_principal' => ['required','string'],
            'items.*.cantidad' => ['required','numeric','gt:0'],
            'items.*.precio_unitario' => ['required','numeric','gte:0'],
        ]);
        $companyId = (int)$r->company_id;

        $purchase = DB::transaction(function () use ($r, $companyId, $inv, $asiento) {
            $items = collect($r->items)->map(fn($it) => [
                'codigo_principal' => $it['codigo_principal'],
                'descripcion' => $it['descripcion'] ?? $it['codigo_principal'],
                'cantidad' => (float)$it['cantidad'],
                'precio_unitario' => (float)$it['precio_unitario'],
                'tarifa' => (float)($it['tarifa'] ?? 15),
                'unidad' => $it['unidad'] ?? 'UNI',
                'descuento' => (float)($it['descuento'] ?? 0),
                'series' => $it['series'] ?? [],
            ])->toArray();

            $totalSinImp = collect($items)->sum(fn($it) => $it['cantidad'] * $it['precio_unitario'] - ($it['descuento'] ?? 0));
            $totalIva = collect($items)->sum(fn($it) => ($it['cantidad'] * $it['precio_unitario'] - ($it['descuento'] ?? 0)) * $it['tarifa'] / 100);

            $purchase = Purchase::create([
                'company_id' => $companyId,
                'contact_id' => $r->contact_id,
                'numero' => $r->numero,
                'fecha_emision' => $r->fecha_emision,
                'establecimiento' => $r->establecimiento ?? '001',
                'punto_emision' => $r->punto_emision ?? '001',
                'autorizacion' => $r->autorizacion ?? '',
                'clave_acceso' => $r->clave_acceso ?? '',
                'sustento_tributario' => $r->sustento_tributario ?? '01',
                'warehouse_id' => $r->warehouse_id,
                'observacion' => $r->observacion ?? '',
                'items' => $items,
                'total_sin_impuestos' => $totalSinImp,
                'total_impuesto' => $totalIva,
                'importe_total' => $totalSinImp + $totalIva,
                'saldo_pendiente' => $totalSinImp + $totalIva,
            ]);

            // Inventario: cada item con código ingresa stock
            foreach ($items as $item) {
                $codigo = trim((string)($item['codigo_principal'] ?? ''));
                $cant = $item['cantidad'];
                if ($codigo === '' || $cant <= 0) continue;
                $product = Product::firstOrCreate(
                    ['company_id' => $companyId, 'codigo' => $codigo],
                    ['descripcion' => $item['descripcion'] ?? $codigo, 'tipo' => 'bien',
                     'precio' => $item['precio_unitario'], 'tarifa_iva' => $item['tarifa']]
                );
                if ($product->tipo !== 'servicio')
                    $inv->handle($product, 'ingreso', $cant, $item['precio_unitario'],
                        'Compra ' . $purchase->numero, $purchase->fecha_emision->toDateString());
            }

            // Asiento contable
            $asiento->handle($purchase);
            return $purchase;
        });

        return response()->json($purchase->load('contact'), 201);
    }

    /** Actualización de compra manual. */
    public function update(Request $r, Purchase $purchase, RegisterInventoryMovement $inv) {
        $r->validate([
            'contact_id' => ['required','exists:contacts,id'],
            'numero' => ['required','string'],
            'fecha_emision' => ['required','date'],
        ]);

        DB::transaction(function () use ($r, $purchase, $inv) {
            $purchase->update([
                'contact_id' => $r->contact_id,
                'numero' => $r->numero,
                'fecha_emision' => $r->fecha_emision,
                'establecimiento' => $r->establecimiento ?? $purchase->establecimiento,
                'punto_emision' => $r->punto_emision ?? $purchase->punto_emision,
                'autorizacion' => $r->autorizacion ?? $purchase->autorizacion,
                'sustento_tributario' => $r->sustento_tributario ?? $purchase->sustento_tributario,
                'warehouse_id' => $r->warehouse_id ?? $purchase->warehouse_id,
                'observacion' => $r->observacion ?? $purchase->observacion,
            ]);
        });

        return $purchase->load('contact');
    }

    /** Anular compra (soft-delete lógico). */
    public function destroy(Purchase $purchase) {
        $purchase->delete();
        return response()->json(['ok' => true]);
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
