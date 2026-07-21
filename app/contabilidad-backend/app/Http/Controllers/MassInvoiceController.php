<?php
namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Contact;
use App\Services\InvoiceEmitter;
use Illuminate\Http\Request;

class MassInvoiceController extends Controller
{
    public function store(Request $r, InvoiceEmitter $emitter)
    {
        $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'invoices' => ['required', 'array', 'min:1'],
            'invoices.*.contact_id' => ['required', 'exists:contacts,id'],
            'invoices.*.items' => ['required', 'array', 'min:1'],
            'invoices.*.items.*.codigo_principal' => ['required', 'string'],
            'invoices.*.items.*.descripcion' => ['required', 'string'],
            'invoices.*.items.*.cantidad' => ['required', 'numeric', 'min:0.01'],
            'invoices.*.items.*.precio_unitario' => ['required', 'numeric', 'min:0'],
            'invoices.*.items.*.tarifa' => ['sometimes', 'numeric'],
            'invoices.*.forma_pago' => ['sometimes', 'in:efectivo,transferencia,tarjeta,credito'],
        ]);

        $company = Company::findOrFail($r->company_id);
        $results = [];

        foreach ($r->invoices as $i => $invData) {
            try {
                $contact = Contact::findOrFail($invData['contact_id']);
                $invoice = $emitter->emit(
                    $company,
                    $contact,
                    $invData['items'],
                    $invData['forma_pago'] ?? 'efectivo'
                );

                $results[] = [
                    'index' => $i,
                    'success' => true,
                    'invoice_id' => $invoice->id,
                    'numero' => $invoice->numero,
                    'contacto' => $contact->razon_social,
                    'total' => $invoice->importe_total,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'index' => $i,
                    'success' => false,
                    'error' => $e->getMessage(),
                    'contacto' => $invData['contact_id'] ?? '?',
                ];
            }
        }

        $exitos = collect($results)->where('success', true)->count();
        $fallas = collect($results)->where('success', false)->count();

        return response()->json([
            'total' => count($results),
            'exitos' => $exitos,
            'fallas' => $fallas,
            'results' => $results,
        ]);
    }
}
