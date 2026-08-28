<?php
namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $r)
    {
        return Branch::withCount(['warehouses', 'emissionPoints', 'invoices'])
            ->when($r->company_id, fn ($q, $id) => $q->where('company_id', $id))
            ->orderBy('estab')->get();
    }

    public function store(Request $r)
    {
        $d = $this->validar($r);
        // La primera sucursal de la empresa es la matriz.
        $d['es_matriz'] = ! Branch::where('company_id', $d['company_id'])->exists();

        return response()->json(Branch::create($d), 201);
    }

    public function update(Request $r, Branch $branch)
    {
        $branch->update($this->validar($r, $branch));

        return $branch->fresh();
    }

    public function destroy(Branch $branch)
    {
        if ($branch->es_matriz) {
            return response()->json(['message' => 'La matriz no se puede eliminar.'], 422);
        }
        if ($branch->invoices()->exists()) {
            return response()->json(['message' => 'Esta sucursal ya tiene facturas emitidas; se puede desactivar, no eliminar.'], 422);
        }
        $branch->delete();

        return response()->noContent();
    }

    private function validar(Request $r, ?Branch $branch = null): array
    {
        return $r->validate([
            'company_id' => [$branch ? 'sometimes' : 'required', 'exists:companies,id'],
            // El establecimiento son 3 dígitos y no se repite dentro de la empresa (regla del SRI).
            'estab' => ['required', 'digits:3', Rule::unique('branches')
                ->where(fn ($q) => $q->where('company_id', $r->company_id ?? $branch?->company_id))
                ->ignore($branch?->id)],
            'nombre'    => ['required', 'string', 'max:150'],
            'direccion' => ['nullable', 'string', 'max:250'],
            'telefono'  => ['nullable', 'string', 'max:60'],
            'activa'    => ['boolean'],
        ]);
    }
}
