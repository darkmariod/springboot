<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Quote;
use App\Services\DocumentCalculator;
use App\Services\InvoiceEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class QuoteController extends Controller {
    use \App\Support\RetryOnLock;

    public function index(Request $r) {
        return Quote::with('contact:id,razon_social', 'invoice:id,numero')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->latest()->get();
    }
    public function store(Request $r, DocumentCalculator $calc) {
        $d = $r->validate(['company_id'=>['required','exists:companies,id'],
            'contact_id'=>['required','exists:contacts,id'],
            'items'=>['required','array','min:1'],
            'items.*.codigo_principal'=>['required','string'],'items.*.descripcion'=>['required','string'],
            'items.*.cantidad'=>['required','numeric','min:0.01'],'items.*.precio_unitario'=>['required','numeric','min:0'],
            'items.*.tarifa'=>['sometimes','numeric'],
            // Sin estas dos reglas la validación descartaba el descuento y el código de porcentaje, y la factura salía sin ellos
            'items.*.descuento'=>['sometimes','nullable','numeric','min:0'],
            'items.*.codigo_porcentaje'=>['sometimes','nullable','string','in:'.implode(',', array_keys(DocumentCalculator::TARIFA_POR_CODIGO))]]);
        $t = $calc->fromItems($d['items']);
        return response()->json(Quote::create(['company_id'=>$d['company_id'],'contact_id'=>$d['contact_id'],
            'items'=>$d['items'],'total_sin_impuestos'=>$t['total_sin_impuestos'],
            'total_impuesto'=>$t['total_impuesto'],'importe_total'=>$t['importe_total']]), 201);
    }

    /** Una cotización → una factura, por el camino normal. Una cotización ya facturada no se factura otra vez (422). */
    public function convert(Request $r, Quote $quote, InvoiceEmitter $emitter) {
        try {
            $invoice = $this->facturar($emitter, (int) $quote->company_id, [$quote->id], null, (string) $r->input('forma_pago', 'efectivo'), null);
        } catch (ValidationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return ['invoice'=>$invoice];
    }

    /**
     * Varias cotizaciones → UNA factura (gasolineras y hospitales juntan las del mes). La factura puede ir a un cliente distinto
     * del de las cotizaciones. Sale por el camino normal del emisor (egreso de inventario, asiento con costo de ventas y
     * comprobante del SRI) y todo ocurre en una sola transacción: si algo falla no queda factura ni cotización marcada.
     */
    public function convertMany(Request $r, InvoiceEmitter $emitter) {
        $d = $r->validate([
            'company_id' => ['required','exists:companies,id'],
            'quote_ids' => ['required','array','min:1'],
            'quote_ids.*' => ['integer','distinct'],
            'contact_id' => ['nullable','exists:contacts,id'],
            'forma_pago' => ['sometimes','in:efectivo,transferencia,tarjeta,credito'],
            'emission_point_id' => ['nullable','exists:emission_points,id'],
        ]);
        $user = $r->user();
        if ($user && ! $user->puedeUsarPunto($d['emission_point_id'] ?? null)) {
            throw ValidationException::withMessages([
                'emission_point_id' => ['No puede facturar con un punto de emisión que no es el suyo.'],
            ]);
        }
        try {
            $invoice = $this->facturar($emitter, (int) $d['company_id'], $d['quote_ids'], $d['contact_id'] ?? null,
                $d['forma_pago'] ?? 'efectivo', $d['emission_point_id'] ?? null);
        } catch (ValidationException|HttpExceptionInterface $e) {
            throw $e;   // cotización ya facturada, de otra empresa o línea imposible: se responde tal cual, sin 500
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);   // p. ej. stock insuficiente de la suma
        }
        return response()->json(['invoice' => $invoice, 'sri_document' => $invoice->sriDocument, 'cotizaciones' => count($d['quote_ids'])], 201);
    }

    /**
     * Lo que saldría de unir estas cotizaciones, sin crear nada: líneas ya unidas, total y cliente por defecto
     * (el de las cotizaciones si todas comparten el mismo). La pantalla lo muestra antes de confirmar.
     */
    public function mergePreview(Request $r, DocumentCalculator $calc) {
        $d = $r->validate([
            'company_id' => ['required','exists:companies,id'],
            'quote_ids' => ['required','array','min:1'],
            'quote_ids.*' => ['integer','distinct'],
        ]);
        $quotes = $this->cargar((int) $d['company_id'], $d['quote_ids'], false);
        $items = $this->unirLineas($quotes);
        $t = $calc->fromItems($items);
        $contactId = $this->clienteComun($quotes);
        return [
            'contact_id' => $contactId,
            'clientes' => Contact::whereIn('id', $quotes->pluck('contact_id')->unique())->get(['id', 'razon_social']),
            'items' => $items,
            'total_sin_impuestos' => $t['total_sin_impuestos'],
            'total_impuesto' => $t['total_impuesto'],
            'importe_total' => $t['importe_total'],
            'cotizaciones' => $quotes->count(),
        ];
    }

    /**
     * El corazón de las dos conversiones: lee las cotizaciones con candado, las valida, une las líneas, emite la factura
     * y las marca como facturadas, todo en una transacción.
     */
    private function facturar(InvoiceEmitter $emitter, int $companyId, array $ids, ?int $contactId, string $formaPago, ?int $emissionPointId): Invoice {
        $company = Company::findOrFail($companyId);
        return $this->conReintentos(fn () => DB::transaction(function () use ($emitter, $company, $ids, $contactId, $formaPago, $emissionPointId) {
            // Se leen aquí dentro: dos cajeros no pueden facturar la misma cotización a la vez
            $quotes = $this->cargar($company->id, $ids, true);
            if ($contactId) {
                $contact = Contact::find($contactId);
                if (! $contact || (int) $contact->company_id !== $company->id) {
                    throw ValidationException::withMessages(['contact_id' => ['El cliente elegido no pertenece a esta empresa.']]);
                }
            } else {
                $comun = $this->clienteComun($quotes);
                if (! $comun) {
                    throw ValidationException::withMessages(['contact_id' => ['Las cotizaciones son de clientes distintos: elige a cuál cliente se factura.']]);
                }
                $contact = Contact::findOrFail($comun);
            }

            $invoice = $emitter->emit($company, $contact, $this->unirLineas($quotes), $formaPago, $emissionPointId);
            foreach ($quotes as $q) {
                $q->update(['estado' => Quote::FACTURADA, 'invoice_id' => $invoice->id]);
            }
            return $invoice;
        }));
    }

    /**
     * Las cotizaciones pedidas, en el orden pedido. Una que no existe o es de otra empresa da 404 (no se dice cuál es de otra);
     * una que ya no está pendiente da 422 con su número y su estado.
     * @return Collection<int, Quote>
     */
    private function cargar(int $companyId, array $ids, bool $bloquear): Collection {
        $q = Quote::whereIn('id', $ids);
        $quotes = ($bloquear ? $q->lockForUpdate() : $q)->get();
        abort_unless($quotes->count() === count($ids) && $quotes->every(fn ($x) => (int) $x->company_id === $companyId),
            404, 'No se encontró alguna de las cotizaciones en esta empresa.');

        $problemas = [];
        foreach ($quotes as $x) {
            if ($x->estado === Quote::PENDIENTE) continue;
            $problemas[] = match ($x->estado) {
                Quote::FACTURADA => 'La cotización #'.$x->id.' ya fue facturada'.($x->invoice ? ' en la factura '.$x->invoice->numero : '').'.',
                'anulada' => 'La cotización #'.$x->id.' está anulada y no se puede facturar.',
                default => 'La cotización #'.$x->id.' no está pendiente (estado: '.$x->estado.').',
            };
        }
        if ($problemas) {
            throw ValidationException::withMessages(['quote_ids' => [implode(' ', $problemas).' Quítalas de la selección y vuelve a intentar.']]);
        }
        return $quotes->sortBy(fn ($x) => array_search($x->id, $ids))->values();
    }

    /** El cliente de todas las cotizaciones si es uno solo; null si hay varios. */
    private function clienteComun(Collection $quotes): ?int {
        $clientes = $quotes->pluck('contact_id')->unique();
        return $clientes->count() === 1 ? (int) $clientes->first() : null;
    }

    /**
     * Une las líneas de las cotizaciones: el mismo artículo con el mismo precio, tarifa de IVA y código de porcentaje es UNA
     * línea con la cantidad sumada (los descuentos en dólares y las series también se suman). Otro precio u otra tarifa
     * es otra línea: no se promedia nada.
     */
    private function unirLineas(Collection $quotes): array {
        $unidas = [];
        foreach ($quotes as $q) {
            foreach ($q->items ?? [] as $it) {
                $clave = implode('|', [
                    trim((string) ($it['codigo_principal'] ?? '')),
                    number_format((float) ($it['precio_unitario'] ?? 0), 6, '.', ''),
                    number_format((float) ($it['tarifa'] ?? 15), 2, '.', ''),
                    (string) ($it['codigo_porcentaje'] ?? ''),
                ]);
                if (! isset($unidas[$clave])) {
                    $unidas[$clave] = $it;
                    $unidas[$clave]['cantidad'] = round((float) ($it['cantidad'] ?? 0), 4);
                    $unidas[$clave]['descuento'] = round((float) ($it['descuento'] ?? 0), 2);
                    $unidas[$clave]['series'] = array_values($it['series'] ?? []);
                    continue;
                }
                $u = &$unidas[$clave];
                $u['cantidad'] = round($u['cantidad'] + (float) ($it['cantidad'] ?? 0), 4);
                $u['descuento'] = round($u['descuento'] + (float) ($it['descuento'] ?? 0), 2);
                $u['series'] = array_values(array_merge($u['series'], $it['series'] ?? []));
                unset($u);
            }
        }
        return array_values($unidas);
    }

    /** Una cotizacion se edita mientras no se haya facturado: el cliente pide cambios. */
    public function update(Request $r, Quote $quote, DocumentCalculator $calc) {
        if ($quote->estado === 'facturada') {
            abort(422, 'No se puede editar: esta cotizacion ya se convirtio en factura.');
        }
        $d = $r->validate([
            'contact_id'=>['sometimes','exists:contacts,id'],
            'items'=>['sometimes','array','min:1'],
            'items.*.codigo_principal'=>['required_with:items','string'],
            'items.*.descripcion'=>['required_with:items','string'],
            'items.*.cantidad'=>['required_with:items','numeric','min:0.01'],
            'items.*.precio_unitario'=>['required_with:items','numeric','min:0'],
            'items.*.tarifa'=>['sometimes','numeric'],
            'items.*.descuento'=>['sometimes','nullable','numeric','min:0'],
            'items.*.codigo_porcentaje'=>['sometimes','nullable','string','in:'.implode(',', array_keys(DocumentCalculator::TARIFA_POR_CODIGO))],
        ]);
        if (isset($d['items'])) {
            $t = $calc->fromItems($d['items']);
            $d = $d + ['total_sin_impuestos'=>$t['total_sin_impuestos'],
                       'total_impuesto'=>$t['total_impuesto'],'importe_total'=>$t['importe_total']];
        }
        $quote->update($d);
        return $quote->fresh();
    }

    /** Solo se puede borrar una cotizacion que todavia no se facturo. */
    public function destroy(Quote $quote)
    {
        if ($quote->estado === "facturada") {
            abort(422, "No se puede eliminar: esta cotizacion ya se convirtio en factura.");
        }
        $quote->delete();
        return response()->noContent();
    }
}
