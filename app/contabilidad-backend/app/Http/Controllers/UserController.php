<?php
namespace App\Http\Controllers;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\PhysicalCount;
use App\Models\StockTransformation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller {
    /**
     * Aislamiento (T11.6): el administrador de una empresa cliente lista, crea, cambia y borra SOLO usuarios de su empresa, y nunca
     * toca al superadministrador de la plataforma (aunque comparta empresa con él). `tenant` ya rechaza los ids y los company_id
     * de otra empresa; lo de aquí es lo que ese middleware no puede saber: quién es superadmin y a qué empresa se crea un usuario.
     */
    public function index(Request $r) {
        $actor = $r->user();
        return User::with('emissionPoint:id,estab,punto,nombre')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->when($actor->estaConfinadoAEmpresa(), fn($q)=>$q->where('company_id',$actor->company_id)->where('es_superadmin', false))
            ->orderBy('name')->get(['id','name','email','rol','emission_point_id','activo','company_id']);
    }
    public function store(Request $r) {
        $actor = $r->user();
        // Un administrador de empresa crea usuarios solo en la suya, sin importar lo que mande. El superadmin elige la empresa (obligatoria).
        $empresa = $actor->estaConfinadoAEmpresa() ? (int) $actor->company_id : null;
        if ($empresa) $r->merge(['company_id' => $empresa]);
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'name'=>['required','string'],
            'email'=>['required','email','unique:users,email'],
            'password'=>['required','string','min:8'],
            'rol'=>['required','in:admin,contador,cajero'],
            'emission_point_id'=>['nullable', $this->puntoDeLaEmpresa((int) $r->input('company_id'))],
            'activo'=>['sometimes','boolean'],
        ]);
        $d['password'] = Hash::make($d['password']);
        // `es_superadmin` no se acepta nunca por aquí (no está en $fillable): el superadmin se crea por consola.
        // fresh(): la respuesta trae también lo que la base asigna por defecto (activo, rol)
        return response()->json(User::create($d)->fresh(), 201);
    }
    public function update(Request $r, User $user) {
        $this->exigirTocable($r, $user);
        $d = $r->validate([
            'name'=>['sometimes','string'],
            'email'=>['sometimes','email', Rule::unique('users','email')->ignore($user->id)],
            'password'=>['nullable','string','min:8'],
            'rol'=>['sometimes','in:admin,contador,cajero'],
            'emission_point_id'=>['nullable', $this->puntoDeLaEmpresa((int) $user->company_id)],
            'activo'=>['sometimes','boolean'],
        ]);
        // `company_id` nunca se cambia desde aquí: pasarle un usuario a otra empresa no es cosa de un administrador de empresa.
        // Como en destroy: un administrador no se deja sin acceso a sí mismo
        if (array_key_exists('activo', $d) && ! $d['activo'] && $user->id === auth()->id()) {
            return response()->json(['message'=>'No puedes desactivar tu propio usuario.'], 422);
        }
        if (! empty($d['password'])) $d['password'] = Hash::make($d['password']);
        else unset($d['password']);
        $user->update($d);
        // Desactivar a alguien le quita TODAS las sesiones en ese momento: un token ya emitido no debe seguir sirviendo (T11.2)
        if (array_key_exists('activo', $d) && ! $d['activo']) {
            $user->tokens()->delete();
        }
        return $user->load('emissionPoint:id,estab,punto,nombre');
    }
    public function destroy(Request $r, User $user) {
        $this->exigirTocable($r, $user);
        if ($user->id === auth()->id()) {
            return response()->json(['message'=>'No puedes eliminar tu propio usuario.'], 422);
        }
        // Un usuario con historial (auditoría o documentos que generó) no se borra: se desactiva (T11.4)
        if ($this->tieneHistorial($user)) return $this->conHistorial();
        try {
            $user->delete();
            $user->tokens()->delete(); // los tokens apuntan al usuario sin llave foránea: se limpian después de borrarlo
        } catch (QueryException $e) {
            // Alguna tabla nueva apunta al usuario y no está en la lista de arriba: la base lo rechaza; que no salga como un 500 con SQL
            if (! str_starts_with((string) $e->getCode(), '23')) throw $e;
            return $this->conHistorial();
        }
        return response()->noContent();
    }

    /**
     * El administrador de una empresa cliente no modifica ni borra a un superadministrador (aunque esté en su misma empresa) ni a un
     * usuario sin empresa (cuenta antigua): `tenant` solo compara los que traen `company_id`, y aquí además se exige que sea de la suya.
     */
    private function exigirTocable(Request $r, User $user): void {
        $actor = $r->user();
        if ($actor->esSuperadmin()) return;
        if ($user->esSuperadmin() || ($actor->estaConfinadoAEmpresa() && (int) $user->company_id !== (int) $actor->company_id)) {
            abort(403, 'No tienes acceso a esa empresa.');
        }
    }

    /** El punto de emisión de un usuario tiene que ser de la empresa del usuario. */
    private function puntoDeLaEmpresa(int $companyId) {
        $regla = Rule::exists('emission_points', 'id');
        return $companyId ? $regla->where('company_id', $companyId) : $regla;
    }

    private function tieneHistorial(User $user): bool {
        return AuditLog::where('user_id', $user->id)->exists()
            || EmailLog::where('user_id', $user->id)->exists()
            || StockTransformation::where('user_id', $user->id)->exists()
            || PhysicalCount::where('user_id', $user->id)->exists();
    }

    private function conHistorial() {
        return response()->json(['message'=>'Este usuario tiene historial. Desactívalo en lugar de eliminarlo.'], 422);
    }
}
