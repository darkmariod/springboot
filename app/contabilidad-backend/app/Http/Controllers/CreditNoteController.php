<?php

namespace App\Http\Controllers;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Product;
use App\Services\DocumentCalculator;
use App\Services\RegisterInventoryMovement;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditNoteController extends Controller
{
    public function index(Request $r)
    {
        return CreditNote::with('contact:id,razon_social', 'invoice:id,numero')
            ->when($r->company_id, fn ($q, $id) => $q->where('company_id', $id))
            ->latest('fecha')->get();
    }

    public function store(Request $r, DocumentCalculator $calc)
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'contact_id' => ['required', 'exists:contacts,id'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'tipo' => ['required', 'in:sri,interna'],
            'motivo' => ['required', 'string'],
            'items' => ['sometimes', 'array'],
            'importe_total' => ['required_without:items', 'numeric', 'min:0.01'],
        ]);

        return DB::transaction(function () use ($d, $calc) {
            if (! empty($d['items'])) {
                $t = $calc->fromItems($d['items']);
                $d['total_sin_impuestos'] = $t['total_sin_impuestos'];
                $d['total_impuesto'] = $t['total_impuesto'];
                $d['importe_total'] = $t['importe_total'];
            }

            $n = CreditNote::create($d + [
                'fecha' => now()->toDateString(),
                'saldo_disponible' => $d['importe_total'],
            ]);

            SimpleEntry::make($d['company_id'], 'Nota de crédito '.$d['tipo'].' — '.$d['motivo'], [
                ['codigo' => '4.1.02', 'nombre' => 'Devoluciones y descuentos en ventas', 'tipo' => 'gasto',
                    'debe' => $d['importe_total'], 'haber' => 0, 'ref' => 'NC-'.$n->id],
                ['codigo' => '1.1.03', 'nombre' => 'Cuentas por cobrar clientes', 'tipo' => 'activo',
                    'debe' => 0, 'haber' => $d['importe_total'], 'ref' => 'NC-'.$n->id],
            ], $n);

            // Devolver stock y series si hay invoice_id y items con códigos
            if (! empty($d['invoice_id']) && ! empty($d['items'])) {
                $invoice = Invoice::find($d['invoice_id']);
                if ($invoice) {
                    $itemsFinal = $d['items'];
                    foreach ($itemsFinal as &$item) {
                        $codigo = trim((string) ($item['codigo_principal'] ?? ''));
                        $cant = (float) ($item['cantidad'] ?? 0);
                        if ($codigo === '' || $cant <= 0) {
                            continue;
                        }

                        $product = Product::where('company_id', $d['company_id'])->where('codigo', $codigo)->first();
                        if ($product && $product->tipo !== 'servicio') {
                            // Si el ítem no trae series, tomarlas de la factura original (devolución por línea)
                            $series = $item['series'] ?? [];
                            if (empty($series)) {
                                $invItem = collect($invoice->items ?? [])->firstWhere('codigo_principal', $codigo);
                                $series = $invItem['series'] ?? [];
                            }
                            $item['series'] = $series; // persistir en la NC para que la anulación la revierta

                            app(RegisterInventoryMovement::class)->handle(
                                $product, 'ingreso', $cant, (float) $product->costo_promedio,
                                'Devolución NC '.$n->id, $n->fecha->toDateString(),
                                null, $series
                            );
                        }
                    }
                    unset($item);
                    $n->update(['items' => $itemsFinal]);
                }
            }

            return response()->json($n->load('contact'), 201);
        });
    }

    /**
     * Anular nota de crédito (documento fiscal: NO se borra, se cambia estado).
     */
    public function anular(CreditNote $creditNote)
    {
        if ($creditNote->tipo === 'anulado') {
            return response()->json(['message' => 'La nota de crédito ya está anulada.'], 422);
        }

        return DB::transaction(function () use ($creditNote) {
            // Revertir stock si hay invoice_id y la NC tenía items
            if ($creditNote->invoice_id && ! empty($creditNote->items)) {
                foreach ($creditNote->items as $item) {
                    $codigo = trim((string) ($item['codigo_principal'] ?? ''));
                    $cant = (float) ($item['cantidad'] ?? 0);
                    if ($codigo === '' || $cant <= 0) {
                        continue;
                    }

                    $product = Product::where('company_id', $creditNote->company_id)
                        ->where('codigo', $codigo)->first();
                    if ($product && $product->tipo !== 'servicio') {
                        app(RegisterInventoryMovement::class)->handle(
                            $product, 'egreso', $cant, (float) $product->costo_promedio,
                            'Reverso NC anulada '.$creditNote->id, $creditNote->fecha->toDateString(),
                            null, $item['series'] ?? []
                        );
                    }
                }
            }

            $creditNote->update(['tipo' => 'anulado', 'saldo_disponible' => 0]);

            return response()->json(['ok' => true, 'mensaje' => 'Nota de crédito anulada.']);
        });
    }

    /**
     * Emitir nota de crédito electrónica al SRI (codDoc 04)
     */
    public function emit(Request $r, CreditNote $creditNote)
    {
        if ($creditNote->tipo !== 'sri') {
            return response()->json(['error' => 'Solo se pueden emitir notas de crédito tipo SRI.'], 422);
        }

        $company = Company::find($creditNote->company_id);
        $invoice = $creditNote->invoice;

        $payload = [
            'infoTributaria' => ['codDoc' => '04'],
            'infoNotaCredito' => [
                'fechaEmision' => now()->format('Y-m-d'),
                'dirEstablecimiento' => $company->dir_matriz,
                'obligadoContabilidad' => $company->obligado_contabilidad ? 'SI' : 'NO',
                'tipoIdentificacionComprador' => $creditNote->contact->tipo_identificacion ?? '05',
                'razonSocialComprador' => $creditNote->contact->razon_social ?? '',
                'identificacionComprador' => $creditNote->contact->identificacion ?? '',
                'codDocModificado' => '01',
                'numDocModificado' => $invoice->numero ?? '',
                'motivoModificacion' => $creditNote->motivo,
                'totalSinImpuestos' => number_format($creditNote->total_sin_impuestos ?? $creditNote->importe_total, 2, '.', ''),
                'totalDescuento' => '0.00',
                'totalImpuesto' => number_format($creditNote->total_impuesto ?? 0, 2, '.', ''),
                'importeTotal' => number_format($creditNote->importe_total, 2, '.', ''),
                'moneda' => 'DOLAR',
            ],
            'detalle' => $creditNote->items ?? [],
            'infoAdicional' => [
                'email' => $creditNote->contact->email ?? null,
                'telefono' => $creditNote->contact->telefono ?? null,
            ],
        ];

        $emitir = app(EmitirSriDocument::class);
        $sriDoc = $emitir->execute($creditNote, 'notaCredito', $company, $payload);

        $company->increment('secuencial');

        return response()->json([
            'ok' => true,
            'sri_document' => $sriDoc,
            'mensaje' => 'Nota de crédito emitida al SRI. Clave de acceso: '.$sriDoc->clave_acceso,
        ]);
    }
}
