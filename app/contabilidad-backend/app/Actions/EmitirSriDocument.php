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
     * Completa lo que le falta a un comprobante que ya existe, en orden y sin repetir pasos ya hechos:
     *   generado → firmar (solo si la empresa ya tiene certificado) → firmado → enviar al SRI → enviado → consultar la autorización.
     * Nunca lanza: devuelve ['resultado' => autorizado | pendiente | error, 'estado' => el del comprobante al terminar,
     * 'mensaje' => para el usuario, 'motivo' => 'sin_certificado' cuando no hay con qué firmar].
     * Sin certificado el comprobante se queda "generado": no se inventa ningún estado. Un rechazo del SRI es definitivo.
     */
    public function reanudar(SriDocument $doc, Company $company): array {
        if ($doc->autorizado()) return $this->resultado($doc, 'autorizado', 'Ya estaba autorizado.');
        if ($doc->rechazado()) {
            $detalle = implode(' · ', $this->textosSri($doc->mensajes));
            return $this->resultado($doc, 'error', 'El SRI no autorizó este comprobante'.($detalle !== '' ? ': '.$detalle : '').'. Corrige el problema y emítelo de nuevo.');
        }
        // El comprobante sale al mismo ambiente con que se generó (la clave de acceso lo lleva dentro)
        $ambiente = (string) ($doc->ambiente ?: $company->ambiente);

        // 1) Firmar: solo si nunca se firmó
        if (! $doc->xml_firmado) {
            if (! $company->certificado_p12 || ! $company->certificado_clave) {
                return $this->resultado($doc, 'error', 'Sin certificado .p12: carga el certificado de la empresa para poder firmar y enviar este comprobante. Mientras tanto queda como generado.', 'sin_certificado');
            }
            if (! $doc->xml) return $this->resultado($doc, 'error', 'El comprobante no tiene XML generado: emítelo de nuevo.');
            try {
                $xmlFirmado = $this->facturacion->firmarXml($doc->tipo_comprobante, $doc->xml, $company->certificado_p12, $company->certificado_clave);
            } catch (\Throwable $e) {
                $doc->update(['mensajes'=>['error_firmar'=>$e->getMessage()]]);
                return $this->resultado($doc, 'error', 'No se pudo firmar el comprobante: '.$e->getMessage());
            }
            $doc->update(['xml_firmado'=>$xmlFirmado, 'estado'=>'firmado']);
        }

        // 2) Enviar: solo si todavía no salió. Un comprobante "enviado" ya está en el SRI (reenviarlo da "clave de acceso registrada").
        if (in_array(strtolower((string) $doc->estado), ['generado', 'firmado'], true)) {
            try {
                $recepcion = $this->facturacion->enviarSri($doc->xml_firmado, $ambiente);
            } catch (\Throwable $e) {
                $doc->update(['estado'=>'firmado', 'mensajes'=>['error_enviar'=>$e->getMessage()]]);
                return $this->resultado($doc, 'error', 'No se pudo enviar al SRI: '.$e->getMessage());
            }
            if (! $this->recibido($recepcion)) {
                $motivo = implode(' · ', $this->textosSri($recepcion)) ?: 'El SRI devolvió el comprobante.';
                $doc->update(['estado'=>'firmado', 'mensajes'=>['error_enviar'=>$motivo, 'recepcion'=>$recepcion]]);
                return $this->resultado($doc, 'error', 'El SRI no recibió el comprobante: '.$motivo);
            }
            $doc->update(['estado'=>'enviado', 'mensajes'=>$recepcion]);
        }

        // 3) Consultar la autorización
        try {
            $aut = $this->facturacion->autorizarSri($doc->clave_acceso, $ambiente);
        } catch (\Throwable $e) {
            $doc->update(['estado'=>'enviado', 'mensajes'=>['error_autorizar'=>$e->getMessage()]]);
            return $this->resultado($doc, 'pendiente', 'Enviado al SRI, pero no se pudo consultar la autorización ahora: '.$e->getMessage().'. Vuelve a intentarlo en unos minutos.');
        }
        $datos = $this->extraerAutorizacion($aut);
        $estadoSri = $datos['estado'] ?? null;
        if (SriDocument::esAutorizado($estadoSri)) {
            $doc->update(['estado'=>'AUTORIZADO', 'numero_autorizacion'=>$datos['numeroAutorizacion'] ?? null, 'mensajes'=>$aut]);
            return $this->resultado($doc, 'autorizado', 'Autorizado por el SRI.');
        }
        if (SriDocument::esRechazado($estadoSri)) {
            $doc->update(['estado'=>$estadoSri, 'mensajes'=>$aut]);
            $detalle = implode(' · ', $this->textosSri($datos['mensajes'] ?? $aut));
            return $this->resultado($doc, 'error', 'El SRI no autorizó el comprobante'.($detalle !== '' ? ': '.$detalle : '').'.');
        }
        // Sin respuesta todavía (o "EN PROCESO"): ya está en el SRI, falta que lo autorice
        $doc->update(['estado'=>'enviado', 'mensajes'=>$aut]);
        return $this->resultado($doc, 'pendiente', 'Enviado al SRI; todavía no responde la autorización. Vuelve a intentarlo en unos minutos.');
    }

    private function resultado(SriDocument $doc, string $resultado, string $mensaje, ?string $motivo = null): array {
        return ['resultado'=>$resultado, 'estado'=>$doc->estado, 'mensaje'=>$mensaje, 'motivo'=>$motivo];
    }

    /** La recepción del SRI responde RECIBIDA o DEVUELTA. "Clave de acceso registrada" (43) cuenta como recibida: ya está allá. */
    private function recibido(mixed $recepcion): bool {
        $estado = is_array($recepcion) ? ($recepcion['RespuestaRecepcionComprobante']['estado'] ?? null) : null;
        if ($estado === null || strtoupper((string) $estado) !== 'DEVUELTA') return true;
        return in_array('43', $this->valoresSri($recepcion, 'identificador'), true);
    }

    /** Textos legibles de una respuesta del SRI: sus "mensaje" y su "informacionAdicional", donde estén anidados. */
    private function textosSri(mixed $nodo): array {
        $textos = [];
        foreach (['mensaje', 'informacionAdicional'] as $campo) {
            foreach ($this->valoresSri($nodo, $campo) as $t) {
                if (trim($t) !== '' && ! in_array(trim($t), $textos, true)) $textos[] = trim($t);
            }
        }
        return $textos;
    }

    /** Todos los valores de texto de la clave dada, a cualquier profundidad. */
    private function valoresSri(mixed $nodo, string $clave): array {
        $out = [];
        if (! is_array($nodo)) return $out;
        foreach ($nodo as $k => $v) {
            if ($k === $clave && is_scalar($v)) $out[] = (string) $v;
            elseif (is_array($v)) array_push($out, ...$this->valoresSri($v, $clave));
        }
        return $out;
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
