<?php
namespace App\Http\Controllers;
use App\Models\Invoice;
use App\Models\Withholding;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithholdingController extends Controller {
    public function index(Request $r) {
        return Withholding::with('invoice:id,numero')
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
        $total = 0; $numDoc = null;
        foreach ($xml->impuestos->impuesto ?? [] as $imp) {
            $total += (float)$imp->valorRetenido;
            $numDoc = $numDoc ?? preg_replace('/\D/','',(string)$imp->numDocSustento);
        }
        // Empate automático: numDocSustento (15 dígitos) → estab-pto-secuencial de MI factura
        $invoice = null;
        if ($numDoc && strlen($numDoc) >= 15) {
            $numFactura = substr($numDoc,0,3).'-'.substr($numDoc,3,3).'-'.substr($numDoc,6,9);
            $invoice = Invoice::where('company_id',$r->company_id)->where('numero',$numFactura)->first();
        }
        return DB::transaction(function() use ($r,$it,$numero,$total,$invoice,$contenido) {
            $w = Withholding::create(['company_id'=>$r->company_id,'invoice_id'=>$invoice?->id,
                'numero'=>$numero,'clave_acceso'=>trim((string)$it->claveAcceso) ?: null,
                'fecha'=>now()->toDateString(),'total_retenido'=>round($total,2),'xml'=>$contenido]);
            if ($invoice && $invoice->saldo_pendiente > 0)
                $invoice->decrement('saldo_pendiente', min($total, (float)$invoice->saldo_pendiente));
            // Asiento: retención anticipada (activo) contra CxC
            SimpleEntry::make((int)$r->company_id, 'Retención recibida '.$numero, [
                ['codigo'=>'1.1.06','nombre'=>'Retenciones en la fuente anticipadas','tipo'=>'activo','debe'=>$total,'haber'=>0,'ref'=>$numero],
                ['codigo'=>'1.1.03','nombre'=>'Cuentas por cobrar clientes','tipo'=>'activo','debe'=>0,'haber'=>$total,'ref'=>$numero],
            ], $w);
            return response()->json($w->load('invoice:id,numero'), 201);
        });
    }
}
