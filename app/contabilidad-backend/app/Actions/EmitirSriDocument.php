<?php
namespace App\Actions;
use App\Models\Company;
use App\Models\SriDocument;
use Illuminate\Database\Eloquent\Model;
use LibreriasSri\FacturacionElectronicaLibrary;
class EmitirSriDocument {
    public function __construct(
        private FacturacionElectronicaLibrary $facturacion = new FacturacionElectronicaLibrary(),
    ) {}
    public function execute(Model $documentable, string $tipo, Company $company, array $data): SriDocument {
        $payload = $this->construirPayload($company, $data);
        $xml = $this->facturacion->generarXml($tipo, $payload);
        preg_match('/<claveAcceso>(.*?)<\/claveAcceso>/', $xml, $m);
        $claveAcceso = $m[1] ?? null;
        $doc = SriDocument::create([
            'company_id'=>$company->id, 'documentable_type'=>$documentable->getMorphClass(),
            'documentable_id'=>$documentable->getKey(), 'tipo_comprobante'=>$tipo,
            'clave_acceso'=>$claveAcceso, 'xml'=>$xml, 'ambiente'=>$company->ambiente,
            'estado'=>'generado', 'empresa_data'=>$payload['infoTributaria'], 'fecha_emision'=>now(),
        ]);
        if (!$company->certificado_p12 || !$company->certificado_clave) return $doc;
        $xmlFirmado = $this->facturacion->firmarXml($tipo, $xml, $company->certificado_p12, $company->certificado_clave);
        $doc->update(['xml_firmado'=>$xmlFirmado, 'estado'=>'firmado']);
        $recepcion = $this->facturacion->enviarSri($xmlFirmado, (string)$company->ambiente);
        $doc->update(['estado'=>'enviado', 'mensajes'=>$recepcion]);
        $aut = $this->facturacion->autorizarSri($claveAcceso, (string)$company->ambiente);
        $doc->update(['estado'=>$aut['estado'] ?? 'enviado', 'numero_autorizacion'=>$aut['numeroAutorizacion'] ?? null, 'mensajes'=>$aut]);
        return $doc;
    }
    private function construirPayload(Company $company, array $data): array {
        $infoTributaria = [
            'ambiente'=>(string)$company->ambiente, 'tipoEmision'=>'1', 'razonSocial'=>$company->razon_social,
            'nombreComercial'=>$company->nombre_comercial ?? $company->razon_social, 'ruc'=>$company->ruc,
            'codigoNumerico'=>str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT),
            'codDoc'=>$data['infoTributaria']['codDoc'] ?? '01', 'estab'=>$company->estab, 'ptoEmi'=>$company->pto_emi,
            'secuencial'=>str_pad((string)$company->secuencial,9,'0',STR_PAD_LEFT), 'dirMatriz'=>$company->dir_matriz, 'regimen'=>$company->regimen,
        ];
        return array_replace_recursive($data, ['infoTributaria'=>$infoTributaria]);
    }
}
