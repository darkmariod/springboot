<?php
namespace App\Http\Controllers;
use App\Models\Advance;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\Quote;
use App\Models\StockReservation;
use App\Models\Withholding;
use App\Support\ClaseContribuyente;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        ] + $this->reglasTributarias((int)$r->company_id), $this->mensajesTributarios());
        $this->validarIdentificacion($data['tipo_identificacion'], $data['identificacion']);
        return response()->json(Contact::create($data), 201);
    }
    public function update(Request $r, Contact $contact) {
        if ($r->filled('tipo_identificacion') && $r->filled('identificacion')) {
            $this->validarIdentificacion($r->tipo_identificacion, $r->identificacion);
        }
        $r->validate(['parte_relacionada'=>['sometimes','boolean']]
            + $this->reglasTributarias((int)$contact->company_id), $this->mensajesTributarios());
        $contact->update($r->all());
        return $contact;
    }

    /**
     * Datos tributarios y contables: clase de contribuyente (RISE, emprendedor, negocio popular, otros) y,
     * para proveedores, la cuenta contable por defecto (debe ser de la misma empresa).
     */
    private function reglasTributarias(int $companyId): array {
        return [
            'clase_contribuyente'=>['nullable','string',Rule::in(ClaseContribuyente::claves())],
            'cuenta_contable_id'=>['nullable','integer',Rule::exists('accounts','id')->where('company_id',$companyId)],
        ];
    }

    private function mensajesTributarios(): array {
        return [
            'clase_contribuyente.in'=>'La clase de contribuyente no es válida. Elige RISE, Emprendedor, Negocio popular u Otros.',
            'cuenta_contable_id.exists'=>'La cuenta contable no pertenece a esta empresa.',
        ];
    }

    /**
     * El SRI rechaza la factura si el tipo no coincide con la longitud:
     * 04 = RUC (13 dígitos) · 05 = cédula (10) · 06 = pasaporte · 07 = consumidor final.
     * Se valida aquí para no descubrirlo recién cuando el SRI devuelve el error.
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
    /**
     * Un contacto con documentos (facturas, compras, cotizaciones, notas de crédito, anticipos, retenciones, reservas de stock o
     * consumos de un empleado) no se elimina: borrarlo dejaría esos documentos sin su cliente o proveedor (T11.4).
     * El contacto no tiene casilla de «activo», así que el aviso no ofrece desactivarlo.
     */
    public function destroy(Contact $contact) {
        if ($this->tieneDocumentos($contact)) return $this->conDocumentos();
        try {
            $contact->delete();
        } catch (QueryException $e) {
            // Una tabla nueva apunta al contacto y no está en la lista: la base lo rechaza; que no salga como un 500 con SQL
            if (! str_starts_with((string) $e->getCode(), '23')) throw $e;
            return $this->conDocumentos();
        }
        return response()->noContent();
    }

    private function tieneDocumentos(Contact $contact): bool {
        foreach ([Invoice::class, Purchase::class, Quote::class, CreditNote::class, Advance::class, Withholding::class,
                  StockReservation::class, Employee::class] as $modelo) {
            if ($modelo::where('contact_id', $contact->id)->exists()) return true;
        }
        return false;
    }

    private function conDocumentos() {
        return response()->json(['message'=>'No se puede eliminar: tiene documentos registrados.'], 422);
    }
}
