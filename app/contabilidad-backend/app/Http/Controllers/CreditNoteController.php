<?php

namespace App\Http\Controllers;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\CreditApplication;
use App\Models\CreditNote;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Services\DocumentCalculator;
use App\Services\RegisterInventoryMovement;
use App\Services\SimpleEntry;
use App\Support\AnulacionSri;
use App\Support\ContraAsiento;
use App\Support\CostosInventario;
use App\Support\Cuentas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditNoteController extends Controller
{
    public function index(Request $r)
    {
        return CreditNote::with('contact:id,razon_social', 'invoice:id,numero,importe_total,saldo_pendiente',
                'sriDocument:id,documentable_id,documentable_type,estado,clave_acceso,numero_autorizacion')
            ->when($r->company_id, fn ($q, $id) => $q->where('company_id', $id))
            ->latest('fecha')->latest('id')->get()
            // Una nota interna nunca va al SRI: se reconoce por su número propio (NCI-), también después de anularla
            ->map(fn ($n) => $n->toArray() + ['interna' => $n->tipo === 'interna' || str_starts_with((string) $n->numero, 'NCI-')]);
    }

    /**
     * Emite una nota de crédito (SRI o interna). Siempre corrige UNA factura de la misma empresa y del mismo cliente:
     *  - el total no pasa de lo que aún se puede acreditar a esa factura (su total menos otras notas vigentes);
     *  - al emitirse baja el saldo de la factura en min(total, saldo); lo que sobre queda como saldo a favor (saldo_disponible);
     *  - asiento: Debe Devoluciones (subtotal) + Debe IVA por pagar / Haber CxC (total), más el costo de lo devuelto.
     * La interna no crea documento del SRI ni gasta el secuencial de la empresa: lleva su propio número NCI-xxxxxx.
     */
    public function store(Request $r, DocumentCalculator $calc)
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'contact_id' => ['required', 'exists:contacts,id'],
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'tipo' => ['required', 'in:sri,interna'],
            'motivo' => ['required', 'string'],
            'devuelve_stock' => ['sometimes', 'boolean'],
            'items' => ['sometimes', 'array'],
            'items.*.codigo_principal' => ['required', 'string'],
            'items.*.descripcion' => ['required', 'string'],
            'items.*.cantidad' => ['required', 'numeric', 'min:0.01'],
            'items.*.precio_unitario' => ['required', 'numeric', 'min:0'],
            'items.*.tarifa' => ['sometimes', 'numeric', 'min:0'],
            'importe_total' => ['required_without:items', 'numeric', 'min:0.01'],
        ], [
            'invoice_id.required' => 'Selecciona la factura que corriges.',
            'invoice_id.integer' => 'Selecciona la factura que corriges.',
            'invoice_id.exists' => 'Selecciona la factura que corriges.',
        ]);

        return DB::transaction(function () use ($d, $calc) {
            $invoice = Invoice::whereKey($d['invoice_id'])->lockForUpdate()->first();
            if (! $invoice || (int) $invoice->company_id !== (int) $d['company_id'] || ! $invoice->esFactura()) {
                throw ValidationException::withMessages(['invoice_id' => ['Selecciona la factura que corriges: la elegida no es una factura de esta empresa.']]);
            }
            if ($invoice->estado === 'anulado') {
                throw ValidationException::withMessages(['invoice_id' => ['La factura elegida está anulada: no se le puede hacer una nota de crédito.']]);
            }
            if ((int) $invoice->contact_id !== (int) $d['contact_id']) {
                throw ValidationException::withMessages(['contact_id' => ['La factura elegida es de otro cliente.']]);
            }

            if (! empty($d['items'])) {
                $t = $calc->fromItems($d['items']);
                $sinImpuestos = (float) $t['total_sin_impuestos'];
                $impuesto = (float) $t['total_impuesto'];
                $total = (float) $t['importe_total'];
            } else {
                // Un valor suelto no trae desglose de IVA: se acredita tal cual
                $total = round((float) $d['importe_total'], 2);
                $sinImpuestos = $total;
                $impuesto = 0.0;
            }

            // Tope: lo que la factura todavía admite en notas de crédito
            $yaAcreditado = (float) CreditNote::where('invoice_id', $invoice->id)->where('tipo', '!=', 'anulado')->sum('importe_total');
            $porAcreditar = round((float) $invoice->importe_total - $yaAcreditado, 2);
            if ($total > $porAcreditar + 0.005) {
                throw ValidationException::withMessages(['importe_total' => [
                    'El total de la nota ($'.number_format($total, 2).') supera lo que aún se puede acreditar a la factura '
                    .$invoice->numero.' ($'.number_format(max($porAcreditar, 0), 2).').',
                ]]);
            }

            $n = CreditNote::create([
                'company_id' => $d['company_id'],
                'contact_id' => $d['contact_id'],
                'invoice_id' => $invoice->id,
                'tipo' => $d['tipo'],
                'numero' => $d['tipo'] === 'interna' ? $this->siguienteNumeroInterno((int) $d['company_id']) : null,
                'motivo' => $d['motivo'],
                'items' => $d['items'] ?? null,
                'total_sin_impuestos' => $sinImpuestos,
                'total_impuesto' => $impuesto,
                'importe_total' => $total,
                'fecha' => now()->toDateString(),
                'saldo_disponible' => $total,
            ]);

            // Costo de lo devuelto al inventario (lo llena la devolución de stock de abajo)
            $costoDevuelto = 0.0;

            // Devolver stock y series si la nota trae items y devuelve mercadería (un descuento o ajuste de valor no la devuelve)
            if (! empty($d['items']) && ($d['devuelve_stock'] ?? true)) {
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

                        // Vuelve al costo al que salió en la factura (no al promedio de hoy):
                        // así el costo de ventas que se revierte es el mismo que se asentó al vender.
                        $costo = CostosInventario::costoUnitarioFacturado($invoice->id, $product->id)
                            ?? (float) $product->costo_promedio;
                        $mov = app(RegisterInventoryMovement::class)->handle(
                            $product, 'ingreso', $cant, $costo,
                            'Devolución NC '.$n->id, $n->fecha->toDateString(),
                            null, $series
                        );
                        $costoDevuelto += CostosInventario::valor($mov);
                    }
                }
                unset($item);
                $n->update(['items' => $itemsFinal]);
            }

            // El saldo de la factura baja ya, aquí al emitir la nota; lo que la factura no debe (porque ya la
            // cobraron) queda como saldo a favor de la nota, que se usa en otra factura (CreditApplicationController).
            $aplicado = round(min($total, max(0.0, (float) $invoice->saldo_pendiente)), 2);
            if ($aplicado > 0) {
                $invoice->decrement('saldo_pendiente', $aplicado);
            }
            $n->update(['aplicado_factura' => $aplicado, 'saldo_disponible' => round($total - $aplicado, 2)]);

            // CxC se acredita UNA sola vez, aquí al emitir la nota, en la cuenta de la factura que corrige
            // (CxC normal o de partes relacionadas). La nota es una devolución/descuento de la venta:
            //   Debe Devoluciones y descuentos (subtotal) + Debe IVA por pagar (el impuesto) / Haber CxC (total).
            // Usar un saldo a favor sobrante en otra factura (CreditApplicationController) solo baja saldos y no
            // genera otro asiento, salvo que esa factura viva en la otra cuenta.
            // Si la nota devolvió mercadería, el mismo asiento reversa su costo: Debe Inventario / Haber Costo de ventas.
            $ref = 'NC-'.$n->id;
            $lineas = [Cuentas::linea('devoluciones_ventas', $sinImpuestos, 0, $ref)];
            if ($impuesto > 0) {
                $lineas[] = Cuentas::linea('iva_por_pagar', $impuesto, 0, $ref);
            }
            $lineas[] = Cuentas::linea(Cuentas::cxcDe($n), 0, $total, $ref);
            $costoDevuelto = round($costoDevuelto, 2);
            if ($costoDevuelto > 0) {
                $lineas[] = Cuentas::linea('inventario', $costoDevuelto, 0, $ref);
                $lineas[] = Cuentas::linea('costo_ventas', 0, $costoDevuelto, $ref);
            }
            SimpleEntry::make($d['company_id'], 'Nota de crédito '.$d['tipo'].' — '.$d['motivo'], $lineas, $n);

            $n->load('contact');
            $n->setAttribute('saldo_factura', (float) $invoice->fresh()->saldo_pendiente);

            return response()->json($n, 201);
        });
    }

    /** NCI-000001, NCI-000002… por empresa: el siguiente después del mayor que ya exista (las anuladas también cuentan). */
    private function siguienteNumeroInterno(int $companyId): string
    {
        $max = CreditNote::where('company_id', $companyId)->where('numero', 'like', 'NCI-%')->pluck('numero')
            ->map(fn ($n) => (int) substr((string) $n, 4))->max() ?? 0;

        return sprintf('NCI-%06d', $max + 1);
    }

    /**
     * Anular nota de crédito (documento fiscal: NO se borra, se cambia estado).
     * Reversa lo que hizo al emitirse: contra-asiento de cada línea (el original queda en el diario), devuelve a las
     * facturas el saldo que la nota les había quitado (la suya y las que usaron su saldo a favor), saca de inventario
     * lo que había devuelto y deja la nota sin saldo disponible.
     */
    public function anular(CreditNote $creditNote)
    {
        if ($creditNote->tipo === 'anulado') {
            return response()->json(['message' => 'La nota de crédito ya está anulada.'], 422);
        }

        try {
            return DB::transaction(function () use ($creditNote) {
                $nota = CreditNote::whereKey($creditNote->id)->lockForUpdate()->firstOrFail();
                if ($nota->tipo === 'anulado') {
                    return response()->json(['message' => 'La nota de crédito ya está anulada.'], 422);
                }

                // Revertir stock si hay invoice_id y la NC tenía items
                if ($nota->invoice_id && ! empty($nota->items)) {
                    foreach ($nota->items as $item) {
                        $codigo = trim((string) ($item['codigo_principal'] ?? ''));
                        $cant = (float) ($item['cantidad'] ?? 0);
                        if ($codigo === '' || $cant <= 0) {
                            continue;
                        }

                        $product = Product::where('company_id', $nota->company_id)
                            ->where('codigo', $codigo)->first();
                        // Solo si la nota devolvió esa mercadería (un descuento o ajuste de valor no movió inventario)
                        if ($product && $product->tipo !== 'servicio' && $this->devolvioStock($nota, $product)) {
                            app(RegisterInventoryMovement::class)->handle(
                                $product, 'egreso', $cant, (float) $product->costo_promedio,
                                'Reverso NC anulada '.$nota->id, $nota->fecha->toDateString(),
                                null, $item['series'] ?? []
                            );
                        }
                    }
                }

                // Contabilidad: contra-asiento de la nota y de las reclasificaciones de CxC que dejó al aplicar su saldo
                $concepto = 'Reversión por anulación nota de crédito '.($nota->numero ?? $nota->id);
                $ref = 'Anulación NC-'.$nota->id;
                ContraAsiento::deDocumento($nota, $concepto, $ref);
                JournalEntry::where('company_id', $nota->company_id)
                    ->where('concepto', 'like', 'Aplicación de nota de crédito%')
                    ->whereHas('lines', fn ($q) => $q->where('referencia', 'NC-'.$nota->id))
                    ->orderBy('id')->get()
                    ->each(fn ($asiento) => ContraAsiento::de($asiento, $concepto, $ref));

                // Saldos: la factura de la nota y las facturas donde se usó su saldo a favor vuelven a deber lo que se les quitó
                if ((float) $nota->aplicado_factura > 0 && $nota->invoice_id) {
                    Invoice::whereKey($nota->invoice_id)->increment('saldo_pendiente', (float) $nota->aplicado_factura);
                }
                $aplicaciones = CreditApplication::where('origen_type', $nota->getMorphClass())->where('origen_id', $nota->id)->get();
                foreach ($aplicaciones as $aplicacion) {
                    Invoice::whereKey($aplicacion->invoice_id)->increment('saldo_pendiente', (float) $aplicacion->monto);
                }

                // Una anulada pierde su "tipo" (queda 'anulado'): si era interna se reconoce por su número NCI-
                $interna = $nota->tipo === 'interna' || str_starts_with((string) $nota->numero, 'NCI-');
                $nota->update(['tipo' => 'anulado', 'saldo_disponible' => 0]);

                // No se borra: queda en la lista como anulada. Anularla aquí no la anula en el SRI (ver AnulacionSri).
                return response()->json([
                    'ok' => true,
                    'mensaje' => 'Nota de crédito anulada.',
                    'saldo_factura' => $nota->invoice_id ? (float) Invoice::whereKey($nota->invoice_id)->value('saldo_pendiente') : null,
                ] + AnulacionSri::resumen('La nota de crédito', $nota->numero, $nota->sriDocument()->first(), $interna));
            });
        } catch (\RuntimeException $e) {
            // Por ejemplo, la mercadería devuelta ya se vendió de nuevo y no alcanza el stock para reversar
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** ¿La nota devolvió este producto al inventario? Un descuento o ajuste de valor (devuelve_stock = false) no movió nada. */
    private function devolvioStock(CreditNote $nota, Product $product): bool
    {
        return InventoryMovement::where('company_id', $nota->company_id)->where('product_id', $product->id)
            ->where('concepto', 'Devolución NC '.$nota->id)->exists();
    }

    /**
     * Emitir nota de crédito electrónica al SRI (codDoc 04)
     */
    public function emit(Request $r, CreditNote $creditNote)
    {
        if ($creditNote->tipo !== 'sri') {
            return response()->json(['error' => 'Solo se pueden emitir notas de crédito tipo SRI.', 'message' => 'Solo se pueden emitir notas de crédito tipo SRI.'], 422);
        }
        if ($creditNote->sriDocument()->exists()) {
            return response()->json(['error' => 'Esta nota de crédito ya fue emitida al SRI.', 'message' => 'Esta nota de crédito ya fue emitida al SRI.'], 422);
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

        // Su número es el secuencial con que se emitió (antes la nota quedaba sin número)
        $creditNote->update(['numero' => sprintf('%s-%s-%09d', $company->estab, $company->pto_emi, $company->secuencial)]);
        $company->increment('secuencial');

        return response()->json([
            'ok' => true,
            'sri_document' => $sriDoc,
            'mensaje' => 'Nota de crédito emitida al SRI. Clave de acceso: '.$sriDoc->clave_acceso,
        ]);
    }
}
