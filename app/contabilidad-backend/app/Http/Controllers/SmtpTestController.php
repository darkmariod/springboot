<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Envía un correo de prueba con la configuración SMTP de la empresa.
 * Alimenta el botón "Probar correo" de EDocuments (SignatureConfig.vue).
 * Usa un mailer de Symfony construido al vuelo para no tocar la config global.
 */
class SmtpTestController extends Controller
{
    public function send(Request $request, Company $company)
    {
        $data = $request->validate([
            'destinatario' => ['required', 'email'],
        ]);

        // La clave viene desencriptada por el cast 'encrypted' del modelo.
        if (! $company->smtp_host || ! $company->smtp_user || ! $company->smtp_password) {
            return response()->json([
                'error' => 'Configurá el servidor de correo primero (servidor, usuario y clave) y guardá antes de probar.',
            ], 422);
        }

        $remitente = $company->email_envio ?: $company->smtp_user;

        try {
            $transport = new EsmtpTransport(
                $company->smtp_host,
                (int) ($company->smtp_port ?: 587),
                (bool) $company->smtp_ssl,
            );
            $transport->setUsername($company->smtp_user);
            $transport->setPassword($company->smtp_password);

            $email = (new Email())
                ->from($remitente)
                ->to($data['destinatario'])
                ->subject('Prueba de correo — ' . $company->razon_social)
                ->text(
                    "Este es un correo de prueba del sistema de facturación.\n\n"
                    . "Si lo recibiste, la configuración SMTP de {$company->razon_social} funciona "
                    . "y las facturas electrónicas van a llegar solas al correo del cliente."
                );

            (new Mailer($transport))->send($email);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'No se pudo enviar: ' . $e->getMessage()
                    . ' — revisá servidor, puerto, usuario y la clave de aplicación.',
            ], 422);
        }

        return response()->json([
            'mensaje' => "Correo de prueba enviado a {$data['destinatario']}. Revisá la bandeja (y spam).",
        ]);
    }
}
