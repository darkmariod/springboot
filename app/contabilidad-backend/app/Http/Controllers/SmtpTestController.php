<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\EnviarComprobanteCorreo;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Envía un correo de prueba con la configuración de correo de la empresa.
 * Alimenta el botón "Probar correo" de EDocuments (SignatureConfig.vue).
 * Usa EXACTAMENTE el mismo camino que el envío de los comprobantes (mailer propio de la empresa, mismo remitente, mismo tiempo de
 * espera): si la prueba llega, los comprobantes también. No toca la configuración global de correo.
 */
class SmtpTestController extends Controller
{
    public function send(Request $request, Company $company, EnviarComprobanteCorreo $servicio)
    {
        $data = $request->validate([
            'destinatario' => ['required', 'email'],
        ]);

        try {
            $servicio->enviarPrueba($company, $data['destinatario']);
        } catch (RuntimeException $e) {
            // El mensaje ya viene en español y sin la clave
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'mensaje' => "Correo de prueba enviado a {$data['destinatario']}. Revisa la bandeja (y spam).",
        ]);
    }
}
