<?php
namespace App\Http\Controllers;

use App\Models\CostCenter;
use App\Models\Invoice;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CostCenterController extends Controller
{
    public function index(Request $r)
    {
        return CostCenter::where('company_id', $r->company_id)->orderBy('codigo')->get();
    }

    public function store(Request $r)
    {
        return response()->json(CostCenter::create($this->validar($r)), 201);
    }

    public function update(Request $r, CostCenter $costCenter)
    {
        $costCenter->update($this->validar($r, $costCenter));

        return $costCenter->fresh();
    }

    public function destroy(CostCenter $costCenter)
    {
        if ($costCenter->invoices()->exists() || $costCenter->purchases()->exists()) {
            return response()->json(['message' => 'Este centro ya tiene movimientos; se puede desactivar, no eliminar.'], 422);
        }
        $costCenter->delete();

        return response()->noContent();
    }

    /** Cuánto ingresó y cuánto se gastó en cada centro. */
    public function resultados(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'desde' => ['nullable', 'date'], 'hasta' => ['nullable', 'date'],
        ]);

        $rango = fn ($q) => $q
            ->when($d['desde'] ?? null, fn ($q, $x) => $q->whereDate('fecha_emision', '>=', $x))
            ->when($d['hasta'] ?? null, fn ($q, $x) => $q->whereDate('fecha_emision', '<=', $x));

        $filas = CostCenter::where('company_id', $d['company_id'])->orderBy('codigo')->get()
            ->map(function ($c) use ($rango) {
                $ing = (float) $rango(Invoice::where('cost_center_id', $c->id)->where('estado', '!=', 'anulado'))->sum('importe_total');
                $gas = (float) $rango(Purchase::where('cost_center_id', $c->id))->sum('importe_total');

                return ['codigo' => $c->codigo, 'nombre' => $c->nombre,
                        'ingresos' => round($ing, 2), 'gastos' => round($gas, 2),
                        'resultado' => round($ing - $gas, 2)];
            });

        // Lo que no se imputó a ningún centro
        $sinIng = (float) $rango(Invoice::where('company_id', $d['company_id'])->whereNull('cost_center_id')->where('estado', '!=', 'anulado'))->sum('importe_total');
        $sinGas = (float) $rango(Purchase::where('company_id', $d['company_id'])->whereNull('cost_center_id'))->sum('importe_total');

        return [
            'centros' => $filas,
            'sin_asignar' => ['ingresos' => round($sinIng, 2), 'gastos' => round($sinGas, 2),
                              'resultado' => round($sinIng - $sinGas, 2)],
            'totales' => [
                'ingresos'  => round($filas->sum('ingresos') + $sinIng, 2),
                'gastos'    => round($filas->sum('gastos') + $sinGas, 2),
                'resultado' => round($filas->sum('resultado') + ($sinIng - $sinGas), 2),
            ],
        ];
    }

    private function validar(Request $r, ?CostCenter $c = null): array
    {
        return $r->validate([
            'company_id' => [$c ? 'sometimes' : 'required', 'exists:companies,id'],
            'codigo' => ['required', 'string', 'max:20', Rule::unique('cost_centers')
                ->where(fn ($q) => $q->where('company_id', $r->company_id ?? $c?->company_id))->ignore($c?->id)],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:400'],
            'activo' => ['boolean'],
        ]);
    }
}
