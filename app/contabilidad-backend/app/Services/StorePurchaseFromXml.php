<?php
namespace App\Services;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;

class StorePurchaseFromXml {
    public function __construct(
        private ParseSriPurchaseXml $parser,
        private RegisterInventoryMovement $inventario,
        private GeneratePurchaseJournalEntry $asiento,
    ) {}

    public function handle(Company $company, string $xml): Purchase {
        $d = $this->parser->parse($xml);
        return DB::transaction(function () use ($company, $d) {
            $prov = Contact::firstOrCreate(
                ['company_id'=>$company->id, 'identificacion'=>$d['proveedor']['identificacion']],
                $d['proveedor'] + ['company_id'=>$company->id, 'es_proveedor'=>true, 'es_cliente'=>false]);

            $purchase = Purchase::firstOrCreate(
                ['company_id'=>$company->id, 'clave_acceso'=>$d['comprobante']['clave_acceso']],
                ['contact_id'=>$prov->id, 'numero'=>$d['comprobante']['numero'],
                 'fecha_emision'=>$d['comprobante']['fecha_emision'], 'items'=>$d['items'],
                 'total_sin_impuestos'=>$d['totales']['total_sin_impuestos'],
                 'total_impuesto'=>$d['totales']['total_impuesto'],
                 'importe_total'=>$d['totales']['importe_total'],
                 'saldo_pendiente'=>$d['totales']['importe_total'], 'xml'=>$d['xml']]);

            if (! $purchase->wasRecentlyCreated) return $purchase;

            foreach ($d['items'] as $item) {
                $codigo = trim((string)($item['codigo_principal'] ?? ''));
                $cant = (float)($item['cantidad'] ?? 0);
                if ($codigo === '' || $cant <= 0) continue;
                $prod = Product::firstOrCreate(
                    ['company_id'=>$company->id, 'codigo'=>$codigo],
                    ['descripcion'=>$item['descripcion'] ?? $codigo, 'tipo'=>'bien',
                     'precio'=>$item['precio_unitario'] ?? 0, 'tarifa_iva'=>$item['tarifa'] ?? 15]);
                if ($prod->tipo !== 'servicio')
                    $this->inventario->handle($prod, 'ingreso', $cant, (float)($item['precio_unitario'] ?? 0),
                        'Compra '.$purchase->numero, $purchase->fecha_emision->toDateString());
            }
            $this->asiento->handle($purchase);
            return $purchase;
        });
    }
}
