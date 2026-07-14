<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\SriDocument;
use Illuminate\Http\Request;
use LibreriasSri\FacturacionElectronicaLibrary;

class SriDocumentController extends Controller {
    public function pending(Request $r) {
        return SriDocument::when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->whereNotIn('estado', ['AUTORIZADO','autorizado'])
            ->latest()->get(['id','tipo_comprobante','clave_acceso','estado','fecha_emision']);
    }
    // Autorizar en lote: reintenta la autorización de todos los pendientes con un clic
    public function authorizeBatch(Request $r) {
        $r->validate(['company_id'=>['required','exists:companies,id']]);
        $company = Company::findOrFail($r->company_id);
        $lib = new FacturacionElectronicaLibrary();
        $ok = 0; $fallidos = 0; $sinFirma = 0;
        $docs = SriDocument::where('company_id',$company->id)->whereNotIn('estado',['AUTORIZADO','autorizado'])->get();
        foreach ($docs as $doc) {
            if (!$doc->xml_firmado) { $sinFirma++; continue; } // sin .p12 nunca se firmó
            try {
                $aut = $lib->autorizarSri($doc->clave_acceso, (string)$company->ambiente);
                $estado = $aut['estado'] ?? null;
                if ($estado) { $doc->update(['estado'=>$estado,'numero_autorizacion'=>$aut['numeroAutorizacion'] ?? null,'mensajes'=>$aut]); }
                in_array($estado,['AUTORIZADO','autorizado']) ? $ok++ : $fallidos++;
            } catch (\Throwable $e) { $fallidos++; }
        }
        return ['procesados'=>count($docs),'autorizados'=>$ok,'fallidos'=>$fallidos,
            'sin_firma'=>$sinFirma,
            'mensaje'=>$sinFirma>0 ? "$sinFirma comprobantes sin firmar: cargá el certificado .p12 de la empresa para poder firmarlos y enviarlos." : null];
    }
}
