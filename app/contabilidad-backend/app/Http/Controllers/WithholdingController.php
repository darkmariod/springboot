<?php
namespace App\Http\Controllers;
use App\Models\Invoice;
use App\Models\Withholding;
use App\Services\SimpleEntry;
use App\Support\Cuentas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithholdingController extends Controller {
    public function index(Request $r) {
        // Solo las recibidas: las que la empresa emite a sus proveedores se ven en la compra
        return Withholding::with('invoice:id,numero')
            ->where('tipo', '!=', 'emitida')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->latest()->get();
    }
    public function import(Request $r) {
        $r->validate(['company_id'=>['required','exists:companies,id'],'xml'=>['required','file','max:2048']]);
        $contenido = file_get_contents($r->file('xml')->getRealPath());
        $xml = @simplexml_load_string($contenido);
        if ($xml === false) throw ValidationException::withMessages(['xml'=>['El archivo no es un XML válido.']]);
        // Sobre de autorización con CDATA
        if ($xml->getName() !== 'comprobanteRetencion') {
            $nodo = $xml->getName()==='autorizacion' ? $xml : ($xml->autorizacion ?? null);
            if ($nodo && isset($nodo->comprobante)) $xml = @simplexml_load_string((string)$nodo->comprobante);
        }
        if (!$xml || $xml->getName() !== 'comprobanteRetencion')
            throw ValidationException::withMessages(['xml'=>['No es un comprobante de retención del SRI.']]);

        $it = $xml->infoTributaria;
        $numero = sprintf('%s-%s-%s',(string)$it->estab,(string)$it->ptoEmi,(string)$it->secuencial);
        $clave = trim((string)$it->claveAcceso) ?: null;
        // El mismo comprobante no se registra dos veces: no duplica la retención ni vuelve a descontar la factura
        if ($this->yaRegistrada((int)$r->company_id, $clave))
            throw ValidationException::withMessages(['xml'=>["La retención $numero ya fue registrada en esta empresa; no se importa de nuevo."]]);
        $total = 0; $numDoc = null;
        foreach ($xml->impuestos->impuesto ?? [] as $imp) {
            $total += (float)$imp->valorRetenido;
            $numDoc = $numDoc ?? preg_replace('/\D/','',(string)$imp->numDocSustento);
        }
        // Fecha de la retención: la de emisión que trae el XML (dd/mm/aaaa); sin ella, la de hoy
        $fecha = self::fechaEmision((string)($xml->infoCompRetencion->fechaEmision ?? '')) ?? now()->toDateString();
        // Empate automático: numDocSustento (15 dígitos) → estab-pto-secuencial de MI factura
        $invoice = null;
        if ($numDoc && strlen($numDoc) >= 15) {
            $numFactura = substr($numDoc,0,3).'-'.substr($numDoc,3,3).'-'.substr($numDoc,6,9);
            $invoice = Invoice::where('company_id',$r->company_id)->where('numero',$numFactura)->first();
        }
        return DB::transaction(function() use ($r,$numero,$clave,$fecha,$total,$invoice,$contenido) {
            if ($this->yaRegistrada((int)$r->company_id, $clave))
                throw ValidationException::withMessages(['xml'=>["La retención $numero ya fue registrada en esta empresa; no se importa de nuevo."]]);
            $w = Withholding::create(['company_id'=>$r->company_id,'invoice_id'=>$invoice?->id,
                'numero'=>$numero,'clave_acceso'=>$clave,
                'fecha'=>$fecha,'total_retenido'=>round($total,2),'xml'=>$contenido]);
            if ($invoice && $invoice->saldo_pendiente > 0)
                $invoice->decrement('saldo_pendiente', min($total, (float)$invoice->saldo_pendiente));
            // Asiento: retención anticipada (activo) contra la CxC en que nació la factura
            // (1.1.03, o 1.1.09 si el cliente es parte relacionada); sin factura identificada, la CxC normal
            SimpleEntry::make((int)$r->company_id, 'Retención recibida '.$numero, [
                Cuentas::linea('retenciones_anticipadas', $total, 0, $numero),
                Cuentas::linea($invoice ? Cuentas::cxcDe($invoice) : 'cxc', 0, $total, $numero),
            ], $w);
            return response()->json($w->load('invoice:id,numero'), 201);
        });
    }

    private function yaRegistrada(int $companyId, ?string $clave): bool {
        return $clave !== null && Withholding::where('company_id', $companyId)->where('clave_acceso', $clave)->exists();
    }

    /** dd/mm/aaaa → aaaa-mm-dd; null si no viene o no es una fecha real. */
    private static function fechaEmision(string $f): ?string {
        if (! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($f), $m) || ! checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return null;
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
}
