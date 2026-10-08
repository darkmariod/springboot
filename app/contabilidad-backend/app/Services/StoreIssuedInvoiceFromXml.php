<?php
namespace App\Services;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SriDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registra en la empresa una factura que YA emitió y el SRI autorizó (se descargó del portal).
 *
 * Es un documento real: la clave de acceso, el número de autorización, la fecha y los totales salen del XML,
 * nada se inventa ni se vuelve a firmar o enviar. La factura queda a crédito (CxC abierta) para registrar su
 * cobro después, con el asiento de venta de siempre: Debe CxC (1.1.03, o 1.1.09 si el cliente es parte
 * relacionada) / Haber Ventas / Haber IVA por pagar.
 *
 * Nunca mueve inventario: no hay kárdex ni costo de ventas. Las líneas cuyo código principal coincide con un
 * producto de la empresa guardan su `product_id`; las demás quedan sin enlace.
 */
class StoreIssuedInvoiceFromXml {
    public function __construct(
        private ParseSriIssuedXml $parser,
        private GenerateInvoiceJournalEntry $asiento,
    ) {}

    /** @throws InvalidArgumentException con el motivo en español si el XML no se puede registrar */
    public function handle(Company $company, string $xml): Invoice {
        $d = $this->parser->parse($xml);
        $c = $d['comprobante'];

        $aut = $d['autorizacion'];
        if (! $aut || $aut['estado'] !== 'AUTORIZADO' || ! $aut['numero'])
            throw new InvalidArgumentException('El XML no trae la autorización del SRI (estado AUTORIZADO y número de autorización). Descarga el XML autorizado desde el portal del SRI.');
        if (! $c['clave_acceso'] || ! preg_match('/^\d{49}$/', $c['clave_acceso']))
            throw new InvalidArgumentException('El XML no trae una clave de acceso válida de 49 dígitos.');
        if ($d['emisor']['ruc'] !== $company->ruc)
            throw new InvalidArgumentException("El RUC del emisor ({$d['emisor']['ruc']}) no es el de la empresa activa ({$company->ruc}); solo se registran ventas emitidas por esta empresa.");
        $t = $d['totales'];
        if (abs($t['total_sin_impuestos'] + $t['total_impuesto'] - $t['importe_total']) > 0.02)
            throw new InvalidArgumentException('Los totales del XML no cuadran: el importe total no es igual a la base imponible más los impuestos.');
        if (! $d['comprador']['identificacion'])
            throw new InvalidArgumentException('El XML no trae la identificación del comprador.');

        return DB::transaction(function () use ($company, $d, $c, $aut, $t) {
            $existe = SriDocument::where('company_id', $company->id)->where('clave_acceso', $c['clave_acceso'])->exists()
                || Invoice::where('company_id', $company->id)->where('numero', $c['numero'])
                    ->where(fn ($q) => $q->whereNull('tipo_comprobante')->orWhere('tipo_comprobante', 'factura'))->exists();
            if ($existe)
                throw new InvalidArgumentException("La factura {$c['numero']} ya está registrada en esta empresa (clave de acceso {$c['clave_acceso']}).");

            $comp = $d['comprador'];
            $cliente = Contact::where('company_id', $company->id)->where('identificacion', $comp['identificacion'])->first();
            if (! $cliente) {
                $cliente = Contact::create(['company_id'=>$company->id, 'es_cliente'=>true, 'es_proveedor'=>false,
                    'tipo_identificacion'=>$comp['tipo_identificacion'] ?: '04', 'identificacion'=>$comp['identificacion'],
                    'razon_social'=>$comp['razon_social'] ?: $comp['identificacion'], 'direccion'=>$comp['direccion']]);
            } elseif (! $cliente->es_cliente) {
                $cliente->update(['es_cliente'=>true]);
            }

            // Cada línea con el producto de la empresa que tenga ese código principal (sin él, queda sin enlace)
            $productos = Product::where('company_id', $company->id)
                ->whereIn('codigo', array_filter(array_column($d['items'], 'codigo_principal')))->pluck('id', 'codigo');
            $items = array_map(fn ($i) => $i + ['product_id'=>$productos[$i['codigo_principal']] ?? null], $d['items']);

            $invoice = Invoice::create([
                'company_id'=>$company->id, 'contact_id'=>$cliente->id,
                'branch_id'=>Branch::where('company_id', $company->id)->where('estab', $d['emisor']['estab'])->value('id'),
                'numero'=>$c['numero'], 'tipo_comprobante'=>'factura', 'items'=>$items,
                'total_sin_impuestos'=>$t['total_sin_impuestos'], 'total_impuesto'=>$t['total_impuesto'], 'importe_total'=>$t['importe_total'],
                'forma_pago'=>'credito', 'saldo_pendiente'=>$t['importe_total'], 'estado'=>'emitida',
                'fecha_emision'=>Carbon::parse($c['fecha_emision']),
            ]);
            SriDocument::create([
                'company_id'=>$company->id, 'documentable_type'=>$invoice->getMorphClass(), 'documentable_id'=>$invoice->getKey(),
                'tipo_comprobante'=>'factura', 'clave_acceso'=>$c['clave_acceso'],
                'xml'=>$d['xml'], 'xml_firmado'=>$d['xml'],
                'estado'=>'AUTORIZADO', 'numero_autorizacion'=>$aut['numero'],
                'ambiente'=>$d['emisor']['ambiente'] ?: $company->ambiente,
                'empresa_data'=>['ruc'=>$d['emisor']['ruc'], 'razonSocial'=>$d['emisor']['razon_social'], 'codDoc'=>'01',
                    'estab'=>$d['emisor']['estab'], 'ptoEmi'=>$d['emisor']['pto_emi'], 'secuencial'=>$d['emisor']['secuencial']],
                'mensajes'=>['importado'=>'xml_ventas_emitidas'],
                'fecha_emision'=>$aut['fecha'] ?? Carbon::parse($c['fecha_emision']),
            ]);
            $this->asiento->handle($invoice->fresh('contact'));

            return $invoice->load('contact:id,razon_social,identificacion');
        });
    }
}
