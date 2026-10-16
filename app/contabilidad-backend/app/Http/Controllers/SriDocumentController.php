<?php
namespace App\Http\Controllers;
use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\SriDocument;
use App\Services\EnviarComprobanteCorreo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;

class SriDocumentController extends Controller {
    /** Comprobantes que aún no están autorizados, con el número del documento y el último mensaje del SRI. */
    public function pending(Request $r) {
        $docs = SriDocument::with('documentable')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->whereNotIn('estado', ['AUTORIZADO','autorizado'])
            ->latest()->get()
            ->reject(fn(SriDocument $d)=>$d->documentoAnulado())->values();
        $correos = EmailLog::resumenDe($docs->map->documentable);

        return $docs->map(fn(SriDocument $d)=>$this->fila($d, $correos))->values();
    }

    /**
     * Los últimos comprobantes AUTORIZADOS (hasta 100) con el estado de su correo: son los que se envían al cliente.
     * El estado del correo de toda la lista sale de UNA consulta (EmailLog::resumenDe), no de una por fila.
     */
    public function authorized(Request $r) {
        $docs = SriDocument::with(['documentable' => fn(MorphTo $m) => $m->morphWith([
                Invoice::class => ['contact:id,razon_social'], CreditNote::class => ['contact:id,razon_social'],
            ])])
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->whereIn('estado', ['AUTORIZADO','autorizado'])
            ->latest('id')->limit(100)->get()
            ->reject(fn(SriDocument $d)=>$d->documentoAnulado())->values();
        $correos = EmailLog::resumenDe($docs->map->documentable);

        return $docs->map(fn(SriDocument $d)=>$this->fila($d, $correos))->values();
    }

    /**
     * Autorizar en lote: por cada comprobante pendiente corre los pasos que le faltan (firmar si ya hay certificado →
     * enviar al SRI → consultar la autorización) y devuelve el resultado de cada uno. Con `ids` procesa solo esos
     * (Reenviar seleccionados); sin `ids` procesa todos los pendientes de la empresa (Autorizar todos).
     * Sin certificado un comprobante se queda "generado" con un mensaje claro: no se inventa ningún estado.
     */
    public function authorizeBatch(Request $r, EmitirSriDocument $emitir) {
        $r->validate(['company_id'=>['required','exists:companies,id'], 'ids'=>['sometimes','array'], 'ids.*'=>['integer']]);
        $company = Company::findOrFail($r->company_id);
        $docs = SriDocument::with('documentable')->where('company_id',$company->id)
            ->whereNotIn('estado',['AUTORIZADO','autorizado'])
            ->when($r->has('ids'), fn($q)=>$q->whereIn('id', (array) $r->ids))
            ->orderBy('id')->get()
            // Un documento anulado aquí no se manda al SRI: allá quedaría válido (ver AnulacionSri)
            ->reject(fn(SriDocument $d)=>$d->documentoAnulado())->values();

        $resultados = []; $ok = 0; $pendientes = 0; $fallidos = 0; $sinFirma = 0;
        foreach ($docs as $doc) {
            try {
                $res = $emitir->reanudar($doc, $company);
            } catch (\Throwable $e) {
                $res = ['resultado'=>'error','estado'=>$doc->estado,'mensaje'=>'No se pudo procesar el comprobante: '.$e->getMessage(),'motivo'=>null];
            }
            match ($res['resultado']) { 'autorizado'=>$ok++, 'pendiente'=>$pendientes++, default=>$fallidos++ };
            if (($res['motivo'] ?? null) === 'sin_certificado') $sinFirma++;
            $resultados[] = [
                'id'=>$doc->id,'tipo_comprobante'=>$doc->tipo_comprobante,'clave_acceso'=>$doc->clave_acceso,
                'numero'=>$doc->documentable?->numero,
                'estado'=>$res['estado'],'resultado'=>$res['resultado'],'mensaje'=>$res['mensaje'],
            ];
        }
        return ['procesados'=>$docs->count(),'autorizados'=>$ok,'pendientes'=>$pendientes,'fallidos'=>$fallidos,
            'sin_firma'=>$sinFirma,'resultados'=>$resultados,
            'mensaje'=>$sinFirma>0 ? "$sinFirma comprobantes sin firmar: carga el certificado .p12 de la empresa para poder firmarlos y enviarlos." : null];
    }

    /**
     * Una fila de la lista. `correo` es el estado del envío por correo (enviado | error | sin_enviar) y `comprobante` dice con qué
     * tipo e id se envía por correo desde la pantalla (null si ese comprobante no se envía: nota interna, guía, liquidación).
     */
    private function fila(SriDocument $d, array $correos): array {
        $doc = $d->documentable;
        $tipo = $doc ? EnviarComprobanteCorreo::tipoDe($doc) : null;
        $enviable = $tipo !== null && ! EnviarComprobanteCorreo::esInterna($doc);
        return [
            'id'=>$d->id,'tipo_comprobante'=>$d->tipo_comprobante,'clave_acceso'=>$d->clave_acceso,
            'estado'=>$d->estado,'fecha_emision'=>$d->fecha_emision,
            'numero'=>$doc?->numero ?: $d->numeroComprobante(),
            'cliente'=>$doc?->relationLoaded('contact') ? $doc->contact?->razon_social : null,
            'detalle'=>$this->detalle($d),
            'comprobante'=>$enviable ? ['tipo'=>$tipo,'id'=>$doc->getKey()] : null,
            'correo'=>$enviable
                ? ($correos[$doc->getMorphClass().':'.$doc->getKey()] ?? ['estado'=>'sin_enviar','enviado_en'=>null,'destinatarios'=>[],'motivo'=>null])
                : null,
        ];
    }

    /** El último problema que guardó el sistema o el SRI para este comprobante (para mostrarlo en la lista). */
    private function detalle(SriDocument $d): ?string {
        $m = $d->mensajes;
        if (! is_array($m)) return null;
        foreach (['error_firmar','error_enviar','error_autorizar'] as $k) {
            if (! empty($m[$k]) && is_string($m[$k])) return $m[$k];
        }
        return null;
    }
}
