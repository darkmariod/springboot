<?php
namespace App\Http\Controllers;
use App\Models\Employee;
use App\Models\PayrollLine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller {
    public function index(Request $r) {
        return Employee::with('contact:id,razon_social,identificacion')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->orderBy('nombres')->get();
    }
    public function store(Request $r) {
        return response()->json(Employee::create($this->validated($r))->load('contact:id,razon_social,identificacion'), 201);
    }
    public function update(Request $r, Employee $employee) {
        $employee->update($this->validated($r));
        return $employee->load('contact:id,razon_social,identificacion');
    }
    public function destroy(Employee $employee) {
        // Con roles, movimientos o ausencias registrados no se borra (rompería su historial): se desactiva
        if (PayrollLine::where('employee_id',$employee->id)->exists()
            || $employee->movements()->exists() || $employee->leaves()->exists()) {
            throw ValidationException::withMessages(['employee'=>['Este empleado ya tiene roles, movimientos o ausencias registrados y no se puede eliminar. Desmarca «Activo» para que no entre a los próximos roles.']]);
        }
        $employee->delete();
        return response()->noContent();
    }

    private function validated(Request $r): array {
        return $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'cedula'=>['required','string','max:13'],
            'nombres'=>['required','string'],
            'cargo'=>['nullable','string'],
            'fecha_ingreso'=>['required','date'],
            'sueldo'=>['required','numeric','min:0'],
            'fondos_reserva'=>['boolean'],
            'activo'=>['boolean'],
            // El cliente al que se le facturan los consumos del empleado: debe ser de la misma empresa
            'contact_id'=>['nullable','integer', Rule::exists('contacts','id')->where('company_id', $r->input('company_id'))],
        ], ['contact_id.exists'=>'El cliente elegido no existe en esta empresa.']);
    }
}
