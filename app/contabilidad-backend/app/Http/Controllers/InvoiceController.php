<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\InvoiceEmitter;
use Illuminate\Http\Request;
class InvoiceController extends Controller {
    public function index(Request $r) {
        return Invoice::with('contact:id,razon_social,identificacion,direccion,email', 'sriDocument:id,documentable_id,estado,clave_acceso')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->latest('fecha_emision')->get();
    }
    public function store(Request $r, InvoiceEmitter $emitter) {
        $data = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'contact_id'=>['required','exists:contacts,id'],
            'emission_point_id'=>['nullable','exists:emission_points,id'],
            'forma_pago'=>['sometimes','in:efectivo,transferencia,tarjeta,credito'],
            'items'=>['required','array','min:1'],
            'items.*.codigo_principal'=>['required','string'],
            'items.*.descripcion'=>['required','string'],
            'items.*.cantidad'=>['required','numeric','min:0.01'],
            'items.*.precio_unitario'=>['required','numeric','min:0'],
            'items.*.tarifa'=>['sometimes','numeric'],
            'items.*.series'=>['sometimes','array'],
        ]);
        $company = Company::findOrFail($data['company_id']);
        $user = $r->user();
        if ($user && ! $user->puedeUsarPunto($data['emission_point_id'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'emission_point_id' => ['No puede facturar con un punto de emisión que no es el suyo.'],
            ]);
        }
        $contact = Contact::findOrFail($data['contact_id']);
        $invoice = $emitter->emit($company, $contact, $data['items'], $data['forma_pago'] ?? 'efectivo', $data['emission_point_id'] ?? null);
        return response()->json(['invoice'=>$invoice, 'sri_document'=>$invoice->sriDocument], 201);
    }
}
