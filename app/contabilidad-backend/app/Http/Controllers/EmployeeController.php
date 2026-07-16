<?php
namespace App\Http\Controllers;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller {
    public function index(Request $r) {
        return Employee::when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->orderBy('nombres')->get();
    }
    public function store(Request $r) {
        return response()->json(Employee::create($this->validated($r)), 201);
    }
    public function update(Request $r, Employee $employee) {
        $employee->update($this->validated($r));
        return $employee;
    }
    public function destroy(Employee $employee) { $employee->delete(); return response()->noContent(); }

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
        ]);
    }
}
