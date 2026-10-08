<?php
namespace App\Services;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Purchase;
use App\Support\CostosInventario;
use App\Support\Sustentos;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StorePurchaseFromXml {
    public function __construct(
        private ParseSriPurchaseXml $parser,
        private RegisterInventoryMovement $inventario,
        private GeneratePurchaseJournalEntry $asiento,
    ) {}

    /**
     * @param string|null $sustento Sustento elegido; sin él se deduce de la factura (06 si trae bienes con stock, 01 si no).
     */
    public function handle(Company $company, string $xml, ?string $sustento = null): Purchase {
        if ($sustento !== null && ! Sustentos::existe($sustento)) {
            throw new InvalidArgumentException("El sustento tributario $sustento no está en el catálogo.");
        }
        $d = $this->parser->parse($xml);
        $sustento ??= Sustentos::predeterminado($company->id, $d['items']);
        return DB::transaction(function () use ($company, $d, $sustento) {
            $prov = Contact::firstOrCreate(
                ['company_id'=>$company->id, 'identificacion'=>$d['proveedor']['identificacion']],
                $d['proveedor'] + ['company_id'=>$company->id, 'es_proveedor'=>true, 'es_cliente'=>false]);

            $purchase = Purchase::firstOrCreate(
                ['company_id'=>$company->id, 'clave_acceso'=>$d['comprobante']['clave_acceso']],
                ['contact_id'=>$prov->id, 'numero'=>$d['comprobante']['numero'],
                 'fecha_emision'=>$d['comprobante']['fecha_emision'], 'items'=>$d['items'],
                 'sustento_tributario'=>$sustento, 'tipo_comprobante'=>'factura',
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
                    $this->inventario->handle($prod, 'ingreso', $cant, CostosInventario::costoUnitarioCompra($item),
                        'Compra '.$purchase->numero, $purchase->fecha_emision->toDateString(),
                        null, [], null, $purchase->id);
            }
            $this->asiento->handle($purchase);
            return $purchase;
        });
    }
}
