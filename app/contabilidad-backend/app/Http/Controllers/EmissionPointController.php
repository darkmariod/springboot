<?php
namespace App\Http\Controllers;
use App\Models\EmissionPoint;
use Illuminate\Http\Request;
class EmissionPointController extends Controller {
    public function index(Request $r) {
        return EmissionPoint::when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->orderBy('punto')->get();
    }
    public function store(Request $r) {
        $d = $r->validate(['company_id'=>['required','exists:companies,id'],'estab'=>['required','string','max:3'],
            'punto'=>['required','string','max:3'],'nombre'=>['required','string']]);
        return response()->json(EmissionPoint::create($d + ['secuencial'=>1]), 201);
    }
    public function update(Request $r, EmissionPoint $point) {
        $d = $r->validate([
            'estab'=>['sometimes','string','max:3'],
            'punto'=>['sometimes','string','max:3'],
            'nombre'=>['sometimes','string'],
        ]);
        $point->update($d);
        return $point;
    }
    public function destroy(EmissionPoint $point) { $point->delete(); return response()->noContent(); }
}
