<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Services\InvoiceEmitter;
use App\Services\RegisterInventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $r)
    {
        return Invoice::with('contact:id,razon_social,identificacion,direccion,email,telefono', 'sriDocument:id,documentable_id,estado,clave_acceso,numero_autorizacion,updated_at')
            ->withCount('journalEntries')
            ->when($r->company_id, fn ($q, $id) => $q->where('company_id', $id))->latest('fecha_emision')->get();
    }

    public function store(Request $r, InvoiceEmitter $emitter)
    {
        $data = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'contact_id' => ['required', 'exists:contacts,id'],
            'emission_point_id' => ['nullable', 'exists:emission_points,id'],
            'forma_pago' => ['sometimes', 'in:efectivo,transferencia,tarjeta,credito'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.codigo_principal' => ['required', 'string'],
            'items.*.descripcion' => ['required', 'string'],
            'items.*.cantidad' => ['required', 'numeric', 'min:0.01'],
            'items.*.precio_unitario' => ['required', 'numeric', 'min:0'],
            'items.*.tarifa' => ['sometimes', 'numeric'],
            'items.*.series' => ['sometimes', 'array'],
        ]);
        $company = Company::findOrFail($data['company_id']);
        $user = $r->user();
        if ($user && ! $user->puedeUsarPunto($data['emission_point_id'] ?? null)) {
            throw ValidationException::withMessages([
                'emission_point_id' => ['No puede facturar con un punto de emisión que no es el suyo.'],
            ]);
        }
        $contact = Contact::findOrFail($data['contact_id']);
        try {
            $invoice = $emitter->emit($company, $contact, $data['items'], $data['forma_pago'] ?? 'efectivo', $data['emission_point_id'] ?? null);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'No se pudo emitir la factura.'], 500);
        }

        return response()->json(['invoice' => $invoice, 'sri_document' => $invoice->sriDocument], 201);
    }

    /**
     * Anular factura (documento fiscal: NO se borra).
     * Marca estado 'anulado', crea un contra-asiento (reversión contable, no borra el original),
     * y devuelve el stock + series.
     */
    public function anular(Request $r, Invoice $invoice, RegisterInventoryMovement $inventario)
    {
        if ($invoice->estado === 'anulado') {
            return response()->json(['message' => 'La factura ya está anulada.'], 422);
        }

        // Fix 1: factura AUTORIZADA por el SRI NO se anula por dentro → se reversa con Nota de Crédito
        if (strtoupper((string) $invoice->sriDocument?->estado) === 'AUTORIZADO') {
            return response()->json([
                'message' => 'Una factura autorizada por el SRI se reversa con Nota de Crédito, no se anula.',
            ], 422);
        }

        return DB::transaction(function () use ($invoice, $inventario) {
            // 1) Reversión contable: crear CONTRA-ASIENTO invirtiendo debe↔haber de cada línea del original.
            //    El asiento original QUEDA en el libro diario (rastro de auditoría). NO se borra.
            $asiento = JournalEntry::where('origen_type', $invoice->getMorphClass())
                ->where('origen_id', $invoice->id)->orderBy('id')->first();
            if ($asiento) {
                $reves = JournalEntry::create([
                    'company_id' => $invoice->company_id,
                    'numero' => 'AS-'.str_pad((string) (JournalEntry::where('company_id', $invoice->company_id)->count() + 1), 6, '0', STR_PAD_LEFT),
                    'fecha' => now(),
                    'concepto' => 'Reversión por anulación factura '.$invoice->numero,
                    'origen_type' => $invoice->getMorphClass(),
                    'origen_id' => $invoice->id,
                    'total_debe' => 0,
                    'total_haber' => 0,
                    // El estado se copia del original: si está 'mayorizado', el contra también,
                    // para que el cuadre se mantenga sin re-mayorizar.
                    'estado' => $asiento->estado ?? 'pendiente',
                ]);

                $debe = 0;
                $haber = 0;
                foreach ($asiento->lines as $line) {
                    $reves->lines()->create([
                        'account_id' => $line->account_id,
                        'debe' => $line->haber,
                        'haber' => $line->debe,
                        'referencia' => 'Anulación '.$invoice->numero,
                    ]);
                    $debe += $line->haber;
                    $haber += $line->debe;
                }
                $reves->update([
                    'total_debe' => round($debe, 2),
                    'total_haber' => round($haber, 2),
                ]);
            }

            // 2) Devolver stock (inverso del egreso; soporta combos)
            foreach ($invoice->items ?? [] as $item) {
                $codigo = trim((string) ($item['codigo_principal'] ?? ''));
                $cant = (float) ($item['cantidad'] ?? 0);
                if ($codigo === '' || $cant <= 0) {
                    continue;
                }
                $product = Product::where('company_id', $invoice->company_id)->where('codigo', $codigo)->first();
                if (! $product) {
                    continue;
                }
                if ($product->es_combo) {
                    foreach ($product->components as $c) {
                        $parte = $c->component;
                        if ($parte && $parte->tipo !== 'servicio') {
                            $inventario->handle($parte, 'ingreso', $cant * (float) $c->cantidad,
                                (float) $parte->costo_promedio, 'Anulación '.$invoice->numero,
                                $invoice->fecha_emision->toDateString(), null, $item['series'] ?? []);
                        }
                    }
                } elseif ($product->tipo !== 'servicio') {
                    $inventario->handle($product, 'ingreso', $cant, (float) $product->costo_promedio,
                        'Anulación '.$invoice->numero, $invoice->fecha_emision->toDateString(),
                        null, $item['series'] ?? []);
                }
            }

            // 3) Marcar anulada
            $invoice->update(['estado' => 'anulado']);

            return response()->json([
                'ok' => true,
                'mensaje' => 'Factura '.$invoice->numero.' anulada. Contra-asiento creado y stock devuelto.',
                'invoice' => $invoice->fresh(['contact:id,razon_social,identificacion', 'sriDocument']),
            ]);
        });
    }
}
