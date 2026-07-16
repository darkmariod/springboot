<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller {
    public function index(Request $r) {
        return User::with('emissionPoint:id,estab,punto,nombre')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->orderBy('name')->get(['id','name','email','rol','emission_point_id','activo','company_id']);
    }
    public function store(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'name'=>['required','string'],
            'email'=>['required','email','unique:users,email'],
            'password'=>['required','string','min:8'],
            'rol'=>['required','in:admin,contador,cajero'],
            'emission_point_id'=>['nullable','exists:emission_points,id'],
        ]);
        $d['password'] = Hash::make($d['password']);
        return response()->json(User::create($d), 201);
    }
    public function update(Request $r, User $user) {
        $d = $r->validate([
            'name'=>['sometimes','string'],
            'email'=>['sometimes','email', Rule::unique('users','email')->ignore($user->id)],
            'password'=>['nullable','string','min:8'],
            'rol'=>['sometimes','in:admin,contador,cajero'],
            'emission_point_id'=>['nullable','exists:emission_points,id'],
            'activo'=>['sometimes','boolean'],
        ]);
        if (! empty($d['password'])) $d['password'] = Hash::make($d['password']);
        else unset($d['password']);
        $user->update($d);
        return $user->load('emissionPoint:id,estab,punto,nombre');
    }
}
