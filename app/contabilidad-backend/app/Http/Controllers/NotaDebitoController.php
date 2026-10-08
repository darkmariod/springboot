<?php

namespace App\Http\Controllers;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\SimpleEntry;
use App\Support\AnulacionSri;
use App\Support\ContraAsiento;
use App\Support\Cuentas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Nota de débito (interés, mora, cargo extra). Vive en la tabla de facturas (tipo_comprobante) y SIEMPRE cargará
 * valor a una factura: sube el saldo de esa factura y deja el asiento
 *   Debe CxC (la cuenta de la factura) / Haber Otros ingresos por notas de débito (+ Haber IVA por pagar si lleva IVA).
 * Dos clases: 'sri' (tipo_comprobante 'nota_debito', se emite al SRI) e 'interna' ('nota_debito_interna', número NDI-xxxxxx,
 * sin documento del SRI ni secuencial de la empresa: solo regula saldos).
 * La deuda queda en la factura: la nota guarda saldo_pendiente 0 para que la cartera no la cuente dos veces.
 */
class NotaDebitoController extends Controller
{
    /** Código SRI del IVA según la tarifa (tabla 17 de la ficha técnica). */
    private const CODIGO_IVA = ['0' => '0', '12' => '2', '14' => '3', '15' => '4', '5' => '5', '13' => '10', '8' => '8'];

    public function index(Request $r)
    {
        $companyId = $r->input('company_id');
        $items = Invoice::with('contact', 'sriDocument:id,documentable_id,documentable_type,estado,clave_acceso,numero_autorizacion')
            ->where('company_id', $companyId)
            ->whereIn('tipo_comprobante', Invoice::TIPOS_NOTA_DEBITO)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Invoice $n) => $n->toArray() + ['interna' => $n->tipo_comprobante === 'nota_debito_interna']);
        return response()->json($items);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'tipo' => ['sometimes', 'in:sri,interna'],
            'motivos' => ['required', 'array', 'min:1'],
            'motivos.*.razon' => ['required', 'string'],
            'motivos.*.valor' => ['required', 'numeric', 'min:0.01'],
            'motivos.*.tarifa' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            // La forma de pago solo va en el comprobante del SRI
            'forma_pago' => ['required_unless:tipo,interna', 'nullable', 'string'],
        ], [
            'invoice_id.required' => 'Selecciona la factura a la que se le carga el valor.',
            'invoice_id.integer' => 'Selecciona la factura a la que se le carga el valor.',
            'invoice_id.exists' => 'Selecciona la factura a la que se le carga el valor.',
        ]);
        $interna = ($d['tipo'] ?? 'sri') === 'interna';

        $company = Company::findOrFail($d['company_id']);

        $resultado = DB::transaction(function () use ($d, $company, $interna) {
            $factura = Invoice::whereKey($d['invoice_id'])->lockForUpdate()->first();
            if (! $factura || (int) $factura->company_id !== (int) $company->id || ! $factura->esFactura()) {
                throw ValidationException::withMessages(['invoice_id' => ['Selecciona la factura a la que se le carga el valor: la elegida no es una factura de esta empresa.']]);
            }
            if ($factura->estado === 'anulado') {
                throw ValidationException::withMessages(['invoice_id' => ['La factura elegida está anulada: no se le puede cargar una nota de débito.']]);
            }

            // Motivos con su IVA (los intereses y la mora van a 0%; un cargo administrativo puede llevar 15%)
            $motivos = [];
            $sinImpuestos = 0.0;
            $impuesto = 0.0;
            foreach ($d['motivos'] as $m) {
                $valor = round((float) $m['valor'], 2);
                $tarifa = (float) ($m['tarifa'] ?? 0);
                $motivos[] = ['razon' => $m['razon'], 'valor' => $valor, 'tarifa' => $tarifa];
                $sinImpuestos += $valor;
                $impuesto += round($valor * $tarifa / 100, 2);
            }
            $sinImpuestos = round($sinImpuestos, 2);
            $impuesto = round($impuesto, 2);
            $total = round($sinImpuestos + $impuesto, 2);

            if ($interna) {
                $numero = $this->siguienteNumeroInterno($company->id);
            } else {
                $numero = sprintf('%s-%s-%09d', $company->estab, $company->pto_emi, $company->secuencial);
                $company->increment('secuencial');
            }

            $nd = Invoice::create([
                'company_id' => $company->id,
                'contact_id' => $factura->contact_id,
                'numero' => $numero,
                'fecha_emision' => now()->toDateString(),
                'items' => $motivos,
                'total_sin_impuestos' => $sinImpuestos,
                'total_impuesto' => $impuesto,
                'importe_total' => $total,
                // La deuda vive en la factura (más abajo): la nota no es otra cuenta por cobrar
                'saldo_pendiente' => 0,
                'forma_pago' => $interna ? 'efectivo' : $d['forma_pago'],
                'tipo_comprobante' => $interna ? 'nota_debito_interna' : 'nota_debito',
                'numero_referencia' => $factura->numero,
                'factura_referencia_id' => $factura->id,
            ]);

            // Asiento: Debe CxC (la de la factura: normal o de partes relacionadas) / Haber ingreso (+ IVA por pagar)
            $lineas = [
                Cuentas::linea(Cuentas::cxcDe($factura), $total, 0, $numero),
                Cuentas::linea('ingresos_notas_debito', 0, $sinImpuestos, $numero),
            ];
            if ($impuesto > 0) {
                $lineas[] = Cuentas::linea('iva_por_pagar', 0, $impuesto, $numero);
            }
            SimpleEntry::make($company->id, 'Nota de débito '.($interna ? 'interna' : 'sri').' '.$numero.' — '.collect($motivos)->pluck('razon')->implode(', '), $lineas, $nd);

            $factura->increment('saldo_pendiente', $total);

            return ['nd' => $nd, 'saldo_factura' => (float) $factura->fresh()->saldo_pendiente];
        });

        return response()->json(['ok' => true, 'nota_debito' => $resultado['nd'], 'saldo_factura' => $resultado['saldo_factura']], 201);
    }

    /** NDI-000001, NDI-000002… por empresa: el siguiente después del mayor que ya exista (las anuladas también cuentan). */
    private function siguienteNumeroInterno(int $companyId): string
    {
        $max = Invoice::where('company_id', $companyId)->where('numero', 'like', 'NDI-%')->pluck('numero')
            ->map(fn ($n) => (int) substr((string) $n, 4))->max() ?? 0;

        return sprintf('NDI-%06d', $max + 1);
    }

    /**
     * Anula una nota de débito (no se borra): contra-asiento y baja el saldo que le había subido a la factura.
     * Si la factura ya recibió cobros que cubren ese cargo no se puede anular: primero habría que devolver el cobro.
     */
    public function anular(Invoice $notaDebito)
    {
        if (! in_array($notaDebito->tipo_comprobante, Invoice::TIPOS_NOTA_DEBITO, true)) {
            return response()->json(['message' => 'Este comprobante no es una nota de débito.'], 422);
        }
        if ($notaDebito->estado === 'anulado') {
            return response()->json(['message' => 'La nota de débito ya está anulada.'], 422);
        }

        return DB::transaction(function () use ($notaDebito) {
            $nd = Invoice::whereKey($notaDebito->id)->lockForUpdate()->firstOrFail();
            if ($nd->estado === 'anulado') {
                return response()->json(['message' => 'La nota de débito ya está anulada.'], 422);
            }

            // Las notas anteriores a esta versión no tienen factura ligada ni asiento: solo cambian de estado
            $factura = $nd->factura_referencia_id ? Invoice::whereKey($nd->factura_referencia_id)->lockForUpdate()->first() : null;
            if ($factura) {
                if ((float) $factura->saldo_pendiente + 0.005 < (float) $nd->importe_total) {
                    return response()->json(['message' => 'No se puede anular esta nota de débito: la factura '.$factura->numero
                        .' ya tiene cobros que cubren parte de este cargo (saldo $'.number_format((float) $factura->saldo_pendiente, 2)
                        .', cargo $'.number_format((float) $nd->importe_total, 2).').'], 422);
                }
                ContraAsiento::deDocumento($nd, 'Reversión por anulación nota de débito '.$nd->numero, 'Anulación '.$nd->numero);
                $factura->decrement('saldo_pendiente', (float) $nd->importe_total);
            }
            $nd->update(['estado' => 'anulado']);

            // No se borra: queda en la lista como anulada. Anularla aquí no la anula en el SRI (ver AnulacionSri).
            return response()->json([
                'ok' => true,
                'mensaje' => 'Nota de débito '.$nd->numero.' anulada.',
                'saldo_factura' => $factura ? (float) $factura->fresh()->saldo_pendiente : null,
            ] + AnulacionSri::resumen('La nota de débito', $nd->numero, $nd->sriDocument()->first(), $nd->tipo_comprobante === 'nota_debito_interna'));
        });
    }

    public function emit(Invoice $notaDebito)
    {
        if ($notaDebito->tipo_comprobante === 'nota_debito_interna') {
            return response()->json(['message' => 'Las notas de débito internas no se envían al SRI.'], 422);
        }
        if ($notaDebito->tipo_comprobante !== 'nota_debito') {
            return response()->json(['message' => 'Este comprobante no es una nota de débito.'], 422);
        }
        if ($notaDebito->estado === 'anulado') {
            return response()->json(['message' => 'La nota de débito está anulada: no se puede emitir.'], 422);
        }
        if ($notaDebito->sriDocument()->exists()) {
            return response()->json(['message' => 'Esta nota de débito ya fue emitida al SRI.'], 422);
        }

        $company = Company::find($notaDebito->company_id);
        $contact = Contact::find($notaDebito->contact_id);

        $motivos = collect($notaDebito->items ?? [])->map(fn($m) => [
            'razon' => $m['razon'] ?? $m['descripcion'] ?? '',
            'valor' => number_format($m['valor'] ?? $m['importe_total'] ?? 0, 2, '.', ''),
            'tarifa' => (float) ($m['tarifa'] ?? 0),
        ]);

        $sinImpuestos = round($motivos->sum(fn($m) => (float)$m['valor']), 2);
        // IVA agrupado por tarifa (una nota solo de intereses sale con una línea a 0%)
        $impuestos = $motivos->groupBy(fn($m) => (string) $m['tarifa'])->map(function ($grupo, $tarifa) {
            $base = round($grupo->sum(fn($m) => (float)$m['valor']), 2);
            $valorIva = round($grupo->sum(fn($m) => round((float)$m['valor'] * (float)$m['tarifa'] / 100, 2)), 2);
            return [
                'codigo' => '2',
                'codigoPorcentaje' => self::CODIGO_IVA[rtrim(rtrim(number_format((float)$tarifa, 2, '.', ''), '0'), '.')] ?? '4',
                'tarifa' => (string) (float) $tarifa,
                'baseImponible' => number_format($base, 2, '.', ''),
                'valor' => number_format($valorIva, 2, '.', ''),
            ];
        })->values()->all();
        $total = round($sinImpuestos + array_sum(array_column($impuestos, 'valor')), 2);

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
                'totalSinImpuestos' => number_format($sinImpuestos, 2, '.', ''),
                'impuestos' => $impuestos,
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
            'motivos' => $motivos->map(fn($m) => ['razon' => $m['razon'], 'valor' => $m['valor']])->all(),
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
