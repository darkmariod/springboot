<?php
namespace App\Services;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Purchase;
use App\Support\CostosInventario;
use App\Support\SeriesDeCompra;
use App\Support\SeriesRequeridas;
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
     * @param array $series Series por código de producto (código => series) para los productos que las manejan. El XML no las trae
     *   y el stock no puede subir sin ellas: si faltan lanza SeriesRequeridas y no importa nada (los lotes del SRI quedan como error
     *   en su lista y la factura se importa desde Compras → Importar factura, que sí pide las series).
     */
    public function handle(Company $company, string $xml, ?string $sustento = null, array $series = []): Purchase {
        if ($sustento !== null && ! Sustentos::existe($sustento)) {
            throw new InvalidArgumentException("El sustento tributario $sustento no está en el catálogo.");
        }
        $d = $this->parser->parse($xml);
        $sustento ??= Sustentos::predeterminado($company->id, $d['items']);

        // Una factura que ya se importó se devuelve tal cual, sin pedir series otra vez
        $clave = $d['comprobante']['clave_acceso'];
        if ($clave && ($existente = Purchase::where('company_id', $company->id)->where('clave_acceso', $clave)->first())) {
            return $existente;
        }
        try {
            $d['items'] = SeriesDeCompra::repartirDelXml($company->id, $d['items'], SeriesDeCompra::mapa($series));
        } catch (SeriesRequeridas $e) {
            $detalle = collect($e->lineas())->map(fn ($l) => $l['codigo'].' x'.$l['cantidad'])->implode(', ');
            throw new SeriesRequeridas(
                "Esta factura trae productos con series ({$detalle}): el stock no puede subir sin sus series. Impórtala desde Compras → "
                .'Importar factura (XML del SRI) y escribe el número de serie de cada unidad.',
                $e->lineas()
            );
        }

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
                if ($prod->tipo !== 'servicio') {
                    $mov = $this->inventario->handle($prod, 'ingreso', $cant, CostosInventario::costoUnitarioCompra($item),
                        'Compra '.$purchase->numero, $purchase->fecha_emision->toDateString(),
                        null, [], null, $purchase->id);
                    if (! empty($item['series']))
                        $this->inventario->registrarSeriesNuevas($prod, $item['series'], $purchase->id, $mov->warehouse_id);
                }
            }
            $this->asiento->handle($purchase);
            return $purchase;
        });
    }
}
