<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\RideBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * RIDE (representación impresa del comprobante electrónico) en PDF: factura, nota de crédito y nota de débito.
 * Los datos los arma `RideBuilder` y la vista `resources/views/ride/documento.blade.php` solo los dibuja.
 * Igual que el resto de la API, la empresa llega en `company_id`; un documento de otra empresa no existe para quien lo pide (404).
 */
class RideController extends Controller
{
    public function factura(Request $r, Invoice $invoice, RideBuilder $ride)
    {
        $this->exigirDeLaEmpresa($r, (int) $invoice->company_id);
        abort_unless($invoice->esFactura(), 404, 'No se encontró la factura.');

        return $this->pdf($ride->paraFactura($invoice));
    }

    public function notaCredito(Request $r, CreditNote $creditNote, RideBuilder $ride)
    {
        $this->exigirDeLaEmpresa($r, (int) $creditNote->company_id);

        return $this->pdf($ride->paraNotaCredito($creditNote));
    }

    public function notaDebito(Request $r, Invoice $notaDebito, RideBuilder $ride)
    {
        $this->exigirDeLaEmpresa($r, (int) $notaDebito->company_id);
        abort_unless(in_array($notaDebito->tipo_comprobante, Invoice::TIPOS_NOTA_DEBITO, true), 404, 'No se encontró la nota de débito.');

        return $this->pdf($ride->paraNotaDebito($notaDebito));
    }

    /**
     * El documento tiene que ser de la empresa que lo pide: si no, 404 sin dar un solo dato.
     * Quien pide, además, solo puede ver los de su propia empresa; el administrador es la excepción porque el selector de
     * empresas le abre todas (cambia de empresa y trabaja en ella), así que su RIDE se descarga en la que eligió.
     */
    private function exigirDeLaEmpresa(Request $r, int $companyId): void
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'exists:companies,id']]);
        abort_unless((int) $d['company_id'] === $companyId, 404, 'No se encontró el documento en esta empresa.');
        $usuario = $r->user();
        $propia = $usuario?->company_id;
        abort_if(! $usuario?->esAdmin() && $propia && (int) $propia !== $companyId, 404, 'No se encontró el documento en esta empresa.');
    }

    private function pdf(array $datos)
    {
        $archivo = 'RIDE-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $datos['numero']).'.pdf';

        // Con subconjunto de fuentes el PDF pesa unos KB en vez de llevar la fuente completa (más de 1 MB)
        return Pdf::loadView('ride.documento', ['d' => $datos])
            ->setOption(['enable_font_subsetting' => true])
            ->setPaper('a4', 'portrait')
            ->download($archivo);
    }
}
