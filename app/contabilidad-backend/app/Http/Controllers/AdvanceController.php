<?php
namespace App\Http\Controllers;
use App\Models\Advance;
use App\Models\Contact;
use App\Services\SimpleEntry;
use App\Support\Cuentas;
use App\Support\MovimientosBanco;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvanceController extends Controller {
    public function index(Request $r) {
        return Advance::with('contact:id,razon_social')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->when($r->tipo, fn($q,$t)=>$q->where('tipo',$t))
            ->when($r->con_saldo, fn($q)=>$q->where('saldo','>',0))
            ->latest('fecha')->get();
    }
    /**
     * Anticipo de un cliente (tipo 'cliente', el de siempre) o a un proveedor (tipo 'proveedor').
     *   Cliente:   Debe Caja/Bancos / Haber Anticipos de clientes.
     *   Proveedor: Debe Anticipos a proveedores / Haber Caja/Bancos.
     * Por banco (transferencia, cheque) deja además su movimiento para la conciliación.
     */
    public function store(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'contact_id'=>['required','exists:contacts,id'],
            'tipo'=>['nullable','in:cliente,proveedor'],
            'monto'=>['required','numeric','min:0.01'],
            'forma_pago'=>['required','in:efectivo,transferencia,cheque'],
            'bank_id'=>['nullable','exists:banks,id'],
            'documento'=>['nullable','string','max:100'],
            'nota'=>['nullable','string'],
        ]);
        if (! Contact::where('company_id',$d['company_id'])->whereKey($d['contact_id'])->exists())
            throw ValidationException::withMessages(['contact_id'=>['El cliente o proveedor elegido no pertenece a esta empresa.']]);
        $tipo = $d['tipo'] ?? 'cliente';
        return DB::transaction(function() use ($d,$tipo) {
            $a = Advance::create(Arr::except($d, ['documento']) + ['tipo'=>$tipo, 'fecha'=>now()->toDateString(), 'saldo'=>$d['monto']]);
            $destino = $d['forma_pago']==='efectivo' ? 'caja' : 'bancos';
            $ref = 'ANT-'.$a->id;
            if ($tipo === 'proveedor') {
                $concepto = 'Anticipo a proveedor';
                $lineas = [
                    Cuentas::linea('anticipos_proveedores', $d['monto'], 0, $ref),
                    Cuentas::linea($destino, 0, $d['monto'], $ref),
                ];
            } else {
                $concepto = 'Anticipo de cliente';
                $lineas = [
                    Cuentas::linea($destino, $d['monto'], 0, $ref),
                    Cuentas::linea('anticipos_clientes', 0, $d['monto'], $ref),
                ];
            }
            SimpleEntry::make($d['company_id'], $concepto, $lineas, $a);
            if ($destino === 'bancos') {
                MovimientosBanco::registrar($d['company_id'], $d['bank_id'] ?? null,
                    $tipo === 'proveedor' ? MovimientosBanco::PAGO : MovimientosBanco::COBRO,
                    (float)$d['monto'], $concepto.' '.$ref, $d['documento'] ?? null, $a);
            }
            return response()->json($a->load('contact'), 201);
        });
    }

    public function update(Request $r, Advance $advance) {
        $d = $r->validate([
            'contact_id'=>['sometimes','exists:contacts,id'],
            'monto'=>['sometimes','numeric','min:0.01'],
            'forma_pago'=>['sometimes','in:efectivo,transferencia,cheque'],
            'bank_id'=>['nullable','exists:banks,id'],
            'nota'=>['nullable','string'],
        ]);
        $advance->update($d);
        return $advance->load('contact');
    }

    public function destroy(Advance $advance) {
        if ($advance->saldo > 0) {
            return response()->json(['message' => 'No se puede eliminar un anticipo con saldo pendiente.'], 422);
        }
        $advance->delete();
        return response()->noContent();
    }
}
