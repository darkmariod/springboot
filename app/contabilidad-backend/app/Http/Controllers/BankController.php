<?php
namespace App\Http\Controllers;
use App\Models\Bank;
use Illuminate\Http\Request;
class BankController extends Controller {
    public function index(Request $r) {
        return Bank::when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->orderBy('nombre')->get();
    }
    public function store(Request $r) {
        $data = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'nombre'=>['required','string','max:255'],
            'numero_cuenta'=>['nullable','string'],'cuenta_contable'=>['nullable','string'],
        ]);
        return response()->json(Bank::create($data), 201);
    }
    public function update(Request $r, Bank $bank) { $bank->update($r->all()); return $bank; }
    public function destroy(Bank $bank) { $bank->delete(); return response()->noContent(); }
}
