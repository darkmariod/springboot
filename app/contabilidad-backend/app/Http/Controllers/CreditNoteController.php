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
use App\Support\ComprobanteSri;
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
            // Series que regresan, por línea: obligatorias para un producto con series si la devolución es parcial
            'items.*.series' => ['sometimes', 'nullable', 'array'],
            'items.*.series.*' => ['nullable', 'string'],
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

            // Series que regresan, por línea (las de la nota, o todas las vendidas si se devuelve la línea completa). Se revisan ANTES
            // de crear nada: una devolución que no cuadra no deja ni la nota ni el asiento.
            $seriesDevueltas = [];
            if (! empty($d['items']) && ($d['devuelve_stock'] ?? true)) {
                $seriesDevueltas = $this->seriesQueRegresan($invoice, $d['company_id'], $d['items']);
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
                foreach ($itemsFinal as $i => &$item) {
                    $codigo = trim((string) ($item['codigo_principal'] ?? ''));
                    $cant = (float) ($item['cantidad'] ?? 0);
                    if ($codigo === '' || $cant <= 0) {
                        continue;
                    }

                    $product = Product::where('company_id', $d['company_id'])->where('codigo', $codigo)->first();
                    if ($product && $product->tipo !== 'servicio') {
                        // Producto con series: solo regresan las de esta devolución (ya revisadas arriba); las demás siguen vendidas
                        $series = $seriesDevueltas[$i] ?? [];
                        if ($series) {
                            $item['series'] = $series; // persistir en la NC para que la anulación la revierta
                        }

                        // Vuelve al costo al que salió en la factura (no al promedio de hoy):
                        // así el costo de ventas que se revierte es el mismo que se asentó al vender.
                        $costo = CostosInventario::costoUnitarioFacturado($invoice->id, $product->id)
                            ?? (float) $product->costo_promedio;
                        $mov = app(RegisterInventoryMovement::class)->handle(
                            $product, 'ingreso', $cant, $costo,
                            'Devolución NC '.$n->id, $n->fecha->toDateString(),
                            null, $series, origen: $n   // el kárdex dice de qué nota viene (los de antes se reconocen por el concepto)
                        );
                        $costoDevuelto += CostosInventario::valor($mov);
                        if ($series) {
                            // Para el historial de la serie: qué nota la devolvió
                            \App\Models\ProductSerie::where('company_id', $d['company_id'])->where('product_id', $product->id)
                                ->whereIn('serie', $series)->update(['credit_note_id' => $n->id]);
                        }
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

    /**
     * Series que regresan con la nota, por posición de la línea ([índice => series]). Solo líneas de productos con series.
     *  - La línea trae `series`: tantas como unidades devueltas, sin repetir, y todas deben seguir vendidas en ESTA factura.
     *  - La línea no las trae: si se devuelven todas las unidades que siguen vendidas con series de la factura, regresan todas
     *    (como antes); si es una devolución parcial, 422 pidiendo cuáles regresan (no se adivina).
     * Lanza ValidationException (campo `series`) en español.
     */
    private function seriesQueRegresan(Invoice $invoice, int|string $companyId, array $items): array
    {
        $plan = [];
        $yaElegidas = [];
        foreach ($items as $i => $item) {
            $codigo = trim((string) ($item['codigo_principal'] ?? ''));
            $cant = (float) ($item['cantidad'] ?? 0);
            if ($codigo === '' || $cant <= 0) {
                continue;
            }
            $product = Product::where('company_id', $companyId)->where('codigo', $codigo)->first();
            if (! $product || $product->tipo === 'servicio' || ! $product->maneja_series) {
                continue;
            }
            $falla = fn (string $m) => ValidationException::withMessages(['series' => [$m]]);

            if (abs($cant - round($cant)) > 0.0005) {
                throw $falla("El producto {$codigo} maneja series: la cantidad debe ser un número entero de unidades.");
            }
            $n = (int) round($cant);
            // Las unidades de este producto que esta factura vendió con series y siguen vendidas
            $vendidas = \App\Models\ProductSerie::where('company_id', $companyId)->where('product_id', $product->id)
                ->where('invoice_id', $invoice->id)->where('estado', 'vendida')->orderBy('serie')->pluck('serie')->all();
            $vendidas = array_values(array_diff($vendidas, $yaElegidas));
            $enviadas = \App\Support\SeriesDeCompra::limpiar($item['series'] ?? []);

            if (! $enviadas) {
                if (! $vendidas) {
                    continue;   // la factura no vendió series de este producto (venta anterior al control de series): vuelve solo el stock
                }
                if ($n !== count($vendidas)) {
                    throw $falla("Devuelves {$n} de ".count($vendidas)." unidades vendidas con series del producto {$codigo}: indica cuáles series regresan (".implode(', ', $vendidas).').');
                }
                $enviadas = $vendidas;
            } else {
                if (count($enviadas) !== $n) {
                    throw $falla("El producto {$codigo}: devuelves {$n} unidades, indica exactamente {$n} series (se indicaron ".count($enviadas).').');
                }
                $repetidas = array_keys(array_filter(array_count_values($enviadas), fn ($c) => $c > 1));
                if ($repetidas) {
                    throw $falla('Hay series repetidas en la lista: '.implode(', ', $repetidas).'.');
                }
                foreach ($enviadas as $serie) {
                    if (! in_array($serie, $vendidas, true)) {
                        throw $falla("La serie {$serie} no se vendió en la factura {$invoice->numero} o ya fue devuelta.");
                    }
                }
            }
            $plan[$i] = $enviadas;
            array_push($yaElegidas, ...$enviadas);
        }

        return $plan;
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
                                null, $item['series'] ?? [], origen: $nota
                            );
                            // Las series vuelven a estar vendidas en la factura de la nota (el egreso no la conoce)
                            if (! empty($item['series'])) {
                                \App\Models\ProductSerie::where('company_id', $nota->company_id)->where('product_id', $product->id)
                                    ->whereIn('serie', $item['series'])->update(['invoice_id' => $nota->invoice_id]);
                            }
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
     * Emitir nota de crédito electrónica al SRI (codDoc 04).
     * El comprobante modifica una factura: la librería necesita su número y su fecha de emisión (documento sustento), el valor
     * de la modificación, el IVA agrupado por tarifa, el motivo y el detalle en el mismo formato que el de la factura
     * (DocumentCalculator). Si la nota se creó por un valor suelto, sin líneas, sale una línea al 0% con el motivo.
     */
    public function emit(Request $r, CreditNote $creditNote)
    {
        if ($creditNote->tipo !== 'sri') {
            return response()->json(['error' => 'Solo se pueden emitir notas de crédito tipo SRI.', 'message' => 'Solo se pueden emitir notas de crédito tipo SRI.'], 422);
        }
        if ($creditNote->sriDocument()->exists()) {
            return response()->json(['error' => 'Esta nota de crédito ya fue emitida al SRI.', 'message' => 'Esta nota de crédito ya fue emitida al SRI.'], 422);
        }
        $invoice = $creditNote->invoice;
        if (! $invoice) {
            return response()->json(['message' => 'Esta nota de crédito no está ligada a una factura: el SRI exige el documento que modifica.'], 422);
        }

        $company = Company::find($creditNote->company_id);
        $contact = $creditNote->contact;

        $lineas = $creditNote->items;
        if (empty($lineas)) {
            // Un valor suelto no trae líneas ni IVA: el comprobante lleva una sola, al 0%, con el motivo
            $lineas = [[
                'codigo_principal' => 'NC-VALOR', 'descripcion' => mb_substr((string) $creditNote->motivo, 0, 300),
                'cantidad' => 1, 'precio_unitario' => (float) ($creditNote->total_sin_impuestos ?: $creditNote->importe_total), 'tarifa' => 0,
            ]];
        }
        $calculo = app(DocumentCalculator::class)->fromItems($lineas);

        $payload = [
            'infoTributaria' => ['codDoc' => '04'],
            'infoNotaCredito' => [
                'fechaEmision' => now()->format('Y-m-d'),
                'dirEstablecimiento' => $company->dir_matriz,
                'obligadoContabilidad' => $company->obligado_contabilidad ? 'SI' : 'NO',
                'tipoIdentificacionComprador' => $contact->tipo_identificacion ?? '05',
                'razonSocialComprador' => $contact->razon_social ?? '',
                'identificacionComprador' => $contact->identificacion ?? '',
                'codDocModificado' => '01',
                'numDocModificado' => $invoice->numero,
                'fechaEmisionDocSustento' => ($invoice->fecha_emision ?? $creditNote->fecha)->format('Y-m-d'),
                'totalSinImpuestos' => number_format($calculo['total_sin_impuestos'], 2, '.', ''),
                'valorModificacion' => number_format($calculo['importe_total'], 2, '.', ''),
                'moneda' => 'DOLAR',
                'totalConImpuestos' => array_map(fn ($t) => [
                    'codigo' => $t['codigo'], 'codigoPorcentaje' => $t['codigoPorcentaje'],
                    'baseImponible' => number_format($t['baseImponible'], 2, '.', ''), 'valor' => number_format($t['valor'], 2, '.', ''),
                ], $calculo['impuestos']),
                'motivo' => $creditNote->motivo,
            ],
            'detalle' => $calculo['detalle'],
            'infoAdicional' => ComprobanteSri::infoAdicional($contact->email ?? null, $contact->telefono ?? null),
        ];

        $emitir = app(EmitirSriDocument::class);
        $sriDoc = $emitir->execute($creditNote, 'notaCredito', $company, $payload);

        // Su número es el secuencial con que se emitió (antes la nota quedaba sin número)
        $creditNote->update(['numero' => sprintf('%s-%s-%09d', $company->estab, $company->pto_emi, $company->secuencial)]);
        $company->increment('secuencial');

        // Sin certificado de firma el comprobante se genera pero no sale al SRI: no se le dice al usuario que sí.
        $salioAlSri = strtolower((string) $sriDoc->estado) !== 'generado';

        return response()->json([
            'ok' => true,
            'sri_document' => $sriDoc,
            'mensaje' => $salioAlSri
                ? 'Nota de crédito emitida al SRI. Clave de acceso: '.$sriDoc->clave_acceso
                : 'Nota de crédito generada. Se enviará al SRI cuando cargues el certificado de firma. Clave de acceso: '.$sriDoc->clave_acceso,
        ]);
    }
}
