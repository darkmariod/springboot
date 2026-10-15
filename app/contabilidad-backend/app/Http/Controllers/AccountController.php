<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Support\CodigosCuenta;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        return Account::query()
            ->when($request->company_id, fn ($q, $id) => $q->where('company_id', $id))
            ->orderBy('codigo')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'codigo' => $a->codigo,
                'nombre' => $a->nombre,
                'tipo' => $a->tipo,
                'parent_id' => $a->parent_id,
                // Si tiene movimientos, no se puede borrar ni cambiarle el tipo
                'movimientos' => JournalEntryLine::where('account_id', $a->id)->count(),
            ]);
    }

    /**
     * Código siguiente libre de un nivel del plan (T4.3c). `padre` es el código de la cuenta padre (1.1.01); sin padre, el siguiente
     * del nivel superior. La pantalla lo muestra como sugerencia editable al crear una cuenta.
     */
    public function siguienteCodigo(Request $request)
    {
        $d = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'padre' => ['nullable', 'string', 'max:20'],
        ], ['company_id.required' => 'Falta la empresa.', 'company_id.exists' => 'La empresa no existe.']);

        return CodigosCuenta::siguiente((int) $d['company_id'], $d['padre'] ?? null);
    }

    public function store(Request $request)
    {
        return response()->json(Account::create($this->validated($request)), 201);
    }

    public function update(Request $request, Account $account)
    {
        $d = $this->validated($request, $account->id);

        // Cambiar el tipo de una cuenta con movimientos descuadra los estados financieros:
        // el saldo se calcula distinto según sea activo/gasto o pasivo/ingreso.
        $tieneMovimientos = JournalEntryLine::where('account_id', $account->id)->exists();
        if ($tieneMovimientos && $d['tipo'] !== $account->tipo) {
            abort(422, 'No se puede cambiar el tipo: la cuenta ya tiene movimientos contables.');
        }
        if (($d['parent_id'] ?? null) == $account->id) {
            abort(422, 'Una cuenta no puede ser su propia cuenta padre.');
        }

        $account->update($d);

        return $account;
    }

    public function destroy(Account $account)
    {
        if (JournalEntryLine::where('account_id', $account->id)->exists()) {
            abort(422, 'No se puede eliminar: la cuenta tiene movimientos contables.');
        }
        if (Account::where('parent_id', $account->id)->exists()) {
            abort(422, 'No se puede eliminar: tiene subcuentas.');
        }

        $account->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?int $id = null): array
    {
        $d = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            // Un código por empresa: dos cuentas con el mismo código descuadrarían el árbol y los estados financieros
            'codigo' => ['required', 'string', 'max:20',
                Rule::unique('accounts', 'codigo')
                    ->where('company_id', $request->company_id)->ignore($id)],
            'nombre' => ['required', 'string'],
            'tipo' => ['required', 'in:activo,pasivo,patrimonio,ingreso,gasto'],
            'parent_id' => ['nullable', Rule::exists('accounts', 'id')->where('company_id', $request->company_id)],
        ], [
            'company_id.required' => 'Falta la empresa.', 'company_id.exists' => 'La empresa no existe.',
            'codigo.required' => 'Escribe el código de la cuenta.',
            'codigo.max' => 'El código no puede pasar de 20 caracteres.',
            'codigo.unique' => 'Ya existe una cuenta con el código :input. Usa el código sugerido o elige otro.',
            'nombre.required' => 'Escribe el nombre de la cuenta.',
            'tipo.required' => 'Elige el tipo de la cuenta.', 'tipo.in' => 'El tipo de la cuenta no es válido.',
            'parent_id.exists' => 'La cuenta padre no existe en esta empresa.',
        ]);

        // El árbol se arma con el código: una cuenta hija lleva el código de su padre al inicio (1.1.01 cuelga de 1.1)
        if (! empty($d['parent_id'])) {
            $padre = Account::find($d['parent_id']);
            if ($padre && ! str_starts_with($d['codigo'], $padre->codigo.'.')) {
                abort(422, "El código debe empezar con {$padre->codigo}. porque la cuenta padre es {$padre->codigo} {$padre->nombre}.");
            }
        }

        return $d;
    }
}
