<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Withholding;
use App\Services\EnviarComprobanteCorreo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Envío manual de comprobantes por correo (T10.2) y su historial.
 *   POST /api/comprobantes/{tipo}/{id}/enviar-correo   { company_id, destinatarios?: [correo, ...] }
 *   GET  /api/comprobantes/{tipo}/{id}/correos?company_id=
 * `tipo` es factura | nota_credito | nota_debito | retencion (retención emitida: el id es el de cualquiera de sus filas).
 * Igual que el resto de la API, la empresa llega en `company_id`; un documento de otra empresa no existe para quien lo pide (404).
 * Se puede enviar aunque el SRI todavía no lo autorice: el RIDE lleva su leyenda y el correo lo dice. Una falla real del servidor de
 * correo NO es un error de la petición: responde 200 con estado "error" y la razón.
 */
class ComprobanteCorreoController extends Controller
{
    public function enviar(Request $r, string $tipo, int $id, EnviarComprobanteCorreo $servicio)
    {
        $documento = $this->resolver($r, $tipo, $id);
        $d = $r->validate([
            'destinatarios' => ['sometimes', 'nullable', 'array', 'max:5'],
            'destinatarios.*' => ['required', 'string', 'max:150', 'email'],
        ], [
            'destinatarios.max' => 'Puedes enviar el comprobante hasta a 5 correos.',
            'destinatarios.*.required' => 'Escribe un correo válido.',
            'destinatarios.*.email' => 'El correo ":input" no es válido.',
            'destinatarios.*.max' => 'El correo ":input" es demasiado largo.',
        ]);

        // Sin correos en la petición se usan los del cliente (o proveedor): email y email2
        $destinatarios = ! empty($d['destinatarios']) ? $d['destinatarios'] : $servicio->destinatariosDe($documento);
        $resultado = $servicio->enviar($documento, $destinatarios, false, $r->user()?->id);

        return response()->json($resultado->toArray());
    }

    public function correos(Request $r, string $tipo, int $id, EnviarComprobanteCorreo $servicio)
    {
        $documento = $this->resolver($r, $tipo, $id);
        $company = Company::findOrFail($documento->company_id);
        $sri = $documento->sriDocument()->first();

        return response()->json([
            'sugeridos' => $servicio->destinatariosDe($documento),
            'autorizado' => (bool) $sri?->autorizado(),
            'estado_sri' => $sri?->estado,
            'smtp_configurado' => $servicio->configuracionSmtp($company) !== null,
            'envio_automatico' => (bool) $company->enviar_comprobantes_automaticamente,
            'correos' => EmailLog::where('documentable_type', $documento->getMorphClass())
                ->where('documentable_id', $documento->getKey())
                ->with('user:id,name')->latest('id')->get(),
        ]);
    }

    /** El documento de esa empresa, o 404; las notas internas y lo que no tiene comprobante electrónico, 422. */
    private function resolver(Request $r, string $tipo, int $id): Model
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'exists:companies,id']]);
        $companyId = (int) $d['company_id'];

        $usuario = $r->user();
        $propia = $usuario?->company_id;
        abort_if(! $usuario?->esAdmin() && $propia && (int) $propia !== $companyId, 404, 'No se encontró el documento en esta empresa.');

        $tipo = str_replace('-', '_', $tipo);
        $documento = match ($tipo) {
            'factura' => Invoice::find($id),
            'nota_credito' => CreditNote::find($id),
            'nota_debito' => Invoice::find($id),
            'retencion' => Withholding::find($id),
            default => null,
        };
        abort_unless($documento && (int) $documento->company_id === $companyId && EnviarComprobanteCorreo::tipoDe($documento) === $tipo,
            404, 'No se encontró el documento en esta empresa.');

        if (EnviarComprobanteCorreo::esInterna($documento)) {
            abort(422, 'Las notas internas no se envían por correo.');
        }
        if ($tipo === 'retencion') {
            $documento = app(EnviarComprobanteCorreo::class)->principal($documento);
        }
        if (! $documento->sriDocument()->exists()) {
            abort(422, 'Este documento no tiene comprobante electrónico del SRI, por eso no se envía por correo.');
        }

        return $documento;
    }
}
