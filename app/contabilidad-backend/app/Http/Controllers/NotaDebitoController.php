<?php

namespace App\Http\Controllers;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotaDebitoController extends Controller
{
    public function index(Request $r)
    {
        $companyId = $r->input('company_id');
        $items = Invoice::with('contact')
            ->where('company_id', $companyId)
            ->where('tipo_comprobante', 'nota_debito')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        return response()->json($items);
    }

    public function store(Request $r)
    {
        $r->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'motivos' => 'required|array|min:1',
            'motivos.*.razon' => 'required|string',
            'motivos.*.valor' => 'required|numeric|min:0.01',
            'forma_pago' => 'required|string',
        ]);

        $company = Company::findOrFail($r->input('company_id'));
        $factura = Invoice::findOrFail($r->invoice_id);

        $totalMotivos = collect($r->motivos)->sum('valor');

        $nd = DB::transaction(function () use ($r, $company, $factura, $totalMotivos) {
            $numero = sprintf('%s-%s-%09d', $company->estab, $company->pto_emi, $company->secuencial);
            $nd = Invoice::create([
                'company_id' => $company->id,
                'contact_id' => $factura->contact_id,
                'numero' => $numero,
                'fecha_emision' => now()->toDateString(),
                'items' => $r->motivos,
                'total_sin_impuestos' => $totalMotivos,
                'total_impuesto' => 0,
                'importe_total' => $totalMotivos,
                'saldo_pendiente' => $totalMotivos,
                'tipo_comprobante' => 'nota_debito',
                'numero_referencia' => $factura->numero,
            ]);
            $company->increment('secuencial');
            return $nd;
        });

        return response()->json(['ok' => true, 'nota_debito' => $nd], 201);
    }

    public function emit(Invoice $notaDebito)
    {
        $company = Company::find($notaDebito->company_id);
        $contact = Contact::find($notaDebito->contact_id);

        $motivos = collect($notaDebito->items ?? [])->map(fn($m) => [
            'razon' => $m['razon'] ?? $m['descripcion'] ?? '',
            'valor' => number_format($m['valor'] ?? $m['importe_total'] ?? 0, 2, '.', ''),
        ])->all();

        $total = collect($motivos)->sum(fn($m) => (float)$m['valor']);

        $payload = [
            'infoTributaria' => ['codDoc' => '05'],
            'infoNotaDebito' => [
                'fechaEmision' => now()->format('Y-m-d'),
                'dirEstablecimiento' => $company->dir_matriz,
                'obligadoContabilidad' => $company->obligado_contabilidad === 'SI' ? 'SI' : 'NO',
                'tipoIdentificacionComprador' => $contact->tipo_identificacion ?? '05',
                'razonSocialComprador' => $contact->razon_social,
                'identificacionComprador' => $contact->identificacion,
                'codDocModificado' => '01',
                'numDocModificado' => $notaDebito->numero_referencia ?? '',
                'fechaEmisionDocSustento' => $notaDebito->fecha_emision ? $notaDebito->fecha_emision->format('Y-m-d') : now()->format('Y-m-d'),
                'totalSinImpuestos' => number_format($total, 2, '.', ''),
                'impuestos' => [],
                'valorTotal' => number_format($total, 2, '.', ''),
                'pagos' => [
                    [
                        'formaPago' => $notaDebito->forma_pago ?? '01',
                        'total' => number_format($total, 2, '.', ''),
                        'plazo' => '0',
                        'unidadTiempo' => 'dias',
                    ],
                ],
            ],
            'motivos' => $motivos,
            'infoAdicional' => [
                'email' => $contact->email ?? '',
                'telefono' => $contact->telefono ?? '',
            ],
        ];

        $sriDoc = app(EmitirSriDocument::class)->execute($notaDebito, 'notaDebito', $company, $payload);
        $company->increment('secuencial');

        return response()->json([
            'ok' => true,
            'sri_document' => $sriDoc,
            'mensaje' => 'Nota de débito emitida. Clave: ' . $sriDoc->clave_acceso,
        ]);
    }
}
