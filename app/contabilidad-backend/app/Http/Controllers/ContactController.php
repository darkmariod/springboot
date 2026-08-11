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
        $this->validarIdentificacion($data['tipo_identificacion'], $data['identificacion']);
        return response()->json(Contact::create($data), 201);
    }
    public function update(Request $r, Contact $contact) {
        if ($r->filled('tipo_identificacion') && $r->filled('identificacion')) {
            $this->validarIdentificacion($r->tipo_identificacion, $r->identificacion);
        }
        $contact->update($r->all());
        return $contact;
    }

    /**
     * El SRI rechaza la factura si el tipo no coincide con la longitud:
     * 04 = RUC (13 dígitos) · 05 = cédula (10) · 06 = pasaporte · 07 = consumidor final.
     * Se valida acá para no descubrirlo recién cuando el SRI devuelve el error.
     */
    private function validarIdentificacion(string $tipo, string $numero): void {
        $n = preg_replace('/\D/', '', $numero);
        $esperado = match ($tipo) { '04' => 13, '05' => 10, default => null };
        if ($esperado !== null && strlen($n) !== $esperado) {
            $etiqueta = $tipo === '04' ? 'RUC' : 'cédula';
            throw \Illuminate\Validation\ValidationException::withMessages([
                'identificacion' => ["El {$etiqueta} debe tener {$esperado} dígitos (tiene ".strlen($n)."). El SRI rechaza la factura si no coincide."],
            ]);
        }
    }
    public function destroy(Contact $contact) { $contact->delete(); return response()->noContent(); }
}
