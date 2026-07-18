<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $r)
    {
        return Warehouse::when($r->company_id, fn($q, $id) => $q->where('company_id', $id))
            ->orderBy('nombre')->get();
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'company_id' => 'required|exists:companies,id',
            'codigo' => 'required|string|max:10',
            'nombre' => 'required|string|max:100',
            'por_defecto' => 'boolean',
        ]);

        // Si se marca como defecto, desmarcar las demás
        if ($d['por_defecto'] ?? false) {
            Warehouse::where('company_id', $d['company_id'])->where('por_defecto', true)
                ->update(['por_defecto' => false]);
        }

        return Warehouse::create($d);
    }

    public function update(Request $r, Warehouse $warehouse)
    {
        $d = $r->validate([
            'codigo' => 'sometimes|string|max:10',
            'nombre' => 'sometimes|string|max:100',
            'por_defecto' => 'boolean',
            'activa' => 'boolean',
        ]);

        if ($d['por_defecto'] ?? false) {
            Warehouse::where('company_id', $warehouse->company_id)->where('por_defecto', true)
                ->where('id', '!=', $warehouse->id)->update(['por_defecto' => false]);
        }

        $warehouse->update($d);
        return $warehouse;
    }

    public function destroy(Warehouse $warehouse)
    {
        if ($warehouse->por_defecto) {
            return response()->json(['error' => 'No se puede eliminar la bodega por defecto.'], 422);
        }
        $warehouse->delete();
        return ['ok' => true];
    }
}
