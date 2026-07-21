<?php
namespace App\Http\Controllers;
use App\Models\Contact;
use Illuminate\Http\Request;
class ContactController extends Controller {
    public function index(Request $r) {
        return Contact::when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->orderBy('razon_social')->get();
    }
    public function store(Request $r) {
        $data = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'tipo_identificacion'=>['required','string','max:2'],
            'identificacion'=>['required','string','max:20'],
            'razon_social'=>['required','string','max:255'],
            'es_cliente'=>['boolean'],'es_proveedor'=>['boolean'],
            'nombre_comercial'=>['nullable','string'],'direccion'=>['nullable','string'],
            'telefono'=>['nullable','string'],'email'=>['nullable','email'],
            'email2'=>['nullable','email'],'parte_relacionada'=>['boolean'],
        ]);
        return response()->json(Contact::create($data), 201);
    }
    public function update(Request $r, Contact $contact) { $contact->update($r->all()); return $contact; }
    public function destroy(Contact $contact) { $contact->delete(); return response()->noContent(); }
}
