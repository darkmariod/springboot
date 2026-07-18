<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntryLine;
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
        return $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'codigo' => ['required', 'string', 'max:20',
                Rule::unique('accounts', 'codigo')
                    ->where('company_id', $request->company_id)->ignore($id)],
            'nombre' => ['required', 'string'],
            'tipo' => ['required', 'in:activo,pasivo,patrimonio,ingreso,gasto'],
            'parent_id' => ['nullable', 'exists:accounts,id'],
        ]);
    }
}
