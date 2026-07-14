<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Quote;
use App\Services\DocumentCalculator;
use App\Services\InvoiceEmitter;
use Illuminate\Http\Request;

class QuoteController extends Controller {
    public function index(Request $r) {
        return Quote::with('contact:id,razon_social')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->latest()->get();
    }
    public function store(Request $r, DocumentCalculator $calc) {
        $d = $r->validate(['company_id'=>['required','exists:companies,id'],
            'contact_id'=>['required','exists:contacts,id'],
            'items'=>['required','array','min:1'],
            'items.*.codigo_principal'=>['required','string'],'items.*.descripcion'=>['required','string'],
            'items.*.cantidad'=>['required','numeric','min:0.01'],'items.*.precio_unitario'=>['required','numeric','min:0'],
            'items.*.tarifa'=>['sometimes','numeric']]);
        $t = $calc->fromItems($d['items']);
        return response()->json(Quote::create(['company_id'=>$d['company_id'],'contact_id'=>$d['contact_id'],
            'items'=>$d['items'],'total_sin_impuestos'=>$t['total_sin_impuestos'],
            'total_impuesto'=>$t['total_impuesto'],'importe_total'=>$t['importe_total']]), 201);
    }
    public function convert(Request $r, Quote $quote, InvoiceEmitter $emitter) {
        $formaPago = $r->input('forma_pago', 'efectivo');
        $invoice = $emitter->emit(Company::findOrFail($quote->company_id),
            Contact::findOrFail($quote->contact_id), $quote->items, $formaPago);
        $quote->update(['estado'=>'facturada']);
        return ['invoice'=>$invoice];
    }
}
