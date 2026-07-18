<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Administración configuración e-documents" — same form the KVS creator showed:
 * SRI credentials, signing certificate, timeouts and mail server, all per company.
 */
class EdocConfigController extends Controller
{
    private const URL_PRODUCCION = 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/';
    private const URL_PRUEBAS = 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/';

    public function show(Company $company)
    {
        return [
            'codigo' => str_pad((string) $company->id, 3, '0', STR_PAD_LEFT),
            'nombre' => $company->razon_social,
            'sri_usuario' => $company->sri_usuario ?? $company->ruc,
            'sri_url_produccion' => $company->sri_url_produccion ?: self::URL_PRODUCCION,
            'sri_url_pruebas' => $company->sri_url_pruebas ?: self::URL_PRUEBAS,
            // La firma nunca se devuelve; solo si está cargada y sus datos
            'firma_cargada' => (bool) $company->certificado_p12,
            'cert_sujeto' => $company->cert_sujeto,
            'cert_emitido_desde' => $company->cert_emitido_desde,
            'cert_valido_hasta' => optional($company->cert_valido_hasta)->format('Y-m-d'),
            'tipo_token' => $company->tipo_token,
            'tiempo_generar' => $company->tiempo_generar ?? 300000,
            'tiempo_firmar' => $company->tiempo_firmar ?? 300000,
            'tiempo_enviar' => $company->tiempo_enviar ?? 300000,
            'tiempo_autorizar' => $company->tiempo_autorizar ?? 300000,
            'smtp_host' => $company->smtp_host,
            'smtp_port' => $company->smtp_port,
            'smtp_user' => $company->smtp_user,
            'smtp_ssl' => (bool) $company->smtp_ssl,
            'email_envio' => $company->email_envio,
            'ambiente' => (int) ($company->ambiente ?? 2),
            'edoc_estado' => $company->edoc_estado ?? 'ACTIVO',
            'modo_online' => (bool) $company->modo_online,
        ];
    }

    public function update(Request $request, Company $company)
    {
        $d = $request->validate([
            'sri_usuario' => ['nullable', 'string', 'max:13'],
            'sri_clave' => ['nullable', 'string'],
            'sri_url_produccion' => ['nullable', 'url'],
            'sri_url_pruebas' => ['nullable', 'url'],
            'tipo_token' => ['nullable', 'string'],
            'tiempo_generar' => ['nullable', 'integer', 'min:1000'],
            'tiempo_firmar' => ['nullable', 'integer', 'min:1000'],
            'tiempo_enviar' => ['nullable', 'integer', 'min:1000'],
            'tiempo_autorizar' => ['nullable', 'integer', 'min:1000'],
            'smtp_host' => ['nullable', 'string'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_user' => ['nullable', 'string'],
            'smtp_password' => ['nullable', 'string'],
            'smtp_ssl' => ['nullable', 'boolean'],
            'email_envio' => ['nullable', 'email'],
            'ambiente' => ['nullable', 'in:1,2'],
            'edoc_estado' => ['nullable', 'in:ACTIVO,INACTIVO'],
            'modo_online' => ['nullable', 'boolean'],
            // La firma va aparte (archivo)
            'certificado' => ['nullable', 'file', 'max:5120'],
            'clave_firma' => ['nullable', 'string'],
        ]);

        // Las claves vacías no se tocan (así no se borran al guardar el resto)
        foreach (['sri_clave', 'smtp_password'] as $secreto) {
            if (empty($d[$secreto])) unset($d[$secreto]);
        }

        // Si viene certificado, se valida y se guarda con sus fechas reales
        if ($request->hasFile('certificado')) {
            if (empty($d['clave_firma'])) {
                throw ValidationException::withMessages([
                    'clave_firma' => ['Para cargar la firma necesitás su clave.'],
                ]);
            }
            $contenido = file_get_contents($request->file('certificado')->getRealPath());
            if (! @openssl_pkcs12_read($contenido, $info, $d['clave_firma'])) {
                throw ValidationException::withMessages([
                    'clave_firma' => ['El certificado no abre con esa clave, o no es un .p12 válido.'],
                ]);
            }
            $cert = openssl_x509_parse($info['cert'] ?? '');
            $d['certificado_p12'] = $contenido;
            $d['certificado_clave'] = $d['clave_firma'];
            $d['cert_sujeto'] = $cert['subject']['CN'] ?? null;
            $d['cert_emitido_desde'] = isset($cert['validFrom_time_t'])
                ? date('Y-m-d', $cert['validFrom_time_t']) : null;
            $d['cert_valido_hasta'] = isset($cert['validTo_time_t'])
                ? date('Y-m-d', $cert['validTo_time_t']) : null;
        }
        unset($d['clave_firma'], $d['certificado']);

        $company->update($d);

        return ['ok' => true, 'mensaje' => 'Configuración guardada.', 'config' => $this->show($company->fresh())];
    }
}
