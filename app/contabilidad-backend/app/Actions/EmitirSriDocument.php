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
        try {
            $recepcion = $this->facturacion->enviarSri($xmlFirmado, (string)$company->ambiente);
            $doc->update(['estado'=>'enviado', 'mensajes'=>$recepcion]);
            try {
                $aut = $this->facturacion->autorizarSri($claveAcceso, (string)$company->ambiente);
                $datos = $this->extraerAutorizacion($aut);
                $doc->update([
                    'estado' => $datos['estado'] ?? 'enviado',
                    'numero_autorizacion' => $datos['numeroAutorizacion'] ?? null,
                    'mensajes' => $aut,
                ]);
            } catch (\Throwable $e) {
                $doc->update(['estado'=>'enviado', 'mensajes'=>['error_autorizar'=>$e->getMessage()]]);
            }
        } catch (\Throwable $e) {
            $doc->update(['estado'=>'firmado', 'mensajes'=>['error_enviar'=>$e->getMessage()]]);
        }
        return $doc;
    }
    /**
     * El SRI devuelve la autorización ANIDADA, no en el primer nivel:
     *   RespuestaAutorizacionComprobante.autorizaciones.autorizacion.{estado,numeroAutorizacion}
     * Si se lee plano, una factura AUTORIZADA queda guardada como "enviado".
     * Cuando hay varias autorizaciones, el SRI manda una lista: se toma la primera.
     */
    private function extraerAutorizacion(mixed $aut): array {
        if (! is_array($aut)) return [];
        $nodo = $aut['RespuestaAutorizacionComprobante']['autorizaciones']['autorizacion'] ?? null;
        if ($nodo === null) return is_array($aut) ? $aut : [];   // respuesta plana (compatibilidad)
        // Lista de autorizaciones → la primera; si es una sola, viene como mapa.
        if (is_array($nodo) && ! isset($nodo['estado'])) {
            $nodo = $nodo[0] ?? [];
        }
        return is_array($nodo) ? $nodo : [];
    }

    private function construirPayload(Company $company, array $data): array {
        $infoTributaria = [
            'ambiente'=>(string)$company->ambiente, 'tipoEmision'=>'1', 'razonSocial'=>$company->razon_social,
            'nombreComercial'=>$company->nombre_comercial ?? $company->razon_social, 'ruc'=>$company->ruc,
            'codigoNumerico'=>str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT),
            'codDoc'=>$data['infoTributaria']['codDoc'] ?? '01', 'estab'=>$data['infoTributaria']['estab'] ?? $company->estab,
            'ptoEmi'=>$data['infoTributaria']['ptoEmi'] ?? $company->pto_emi,
            'secuencial'=>str_pad((string)$company->secuencial,9,'0',STR_PAD_LEFT), 'dirMatriz'=>$company->dir_matriz, 'regimen'=>$company->regimen,
        ];
        return array_replace_recursive($data, ['infoTributaria'=>$infoTributaria]);
    }
}
