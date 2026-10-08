<?php
namespace App\Services;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Support\Cuentas;

/**
 * Asiento del ajuste de inventario (pedido de la contadora: "no se puede
 * ajustar sin sustento contable"). Mismo patrón que Compras/Ventas: contra
 * la cuenta de Inventario y Faltantes y sobrantes como contrapartida
 * (ambas salen del plan central, config/cuentas.php).
 *
 * Sobrante (ingreso): sube el inventario, baja el gasto de ajuste.
 * Faltante (egreso): sube el gasto de ajuste, baja el inventario.
 */
class GenerateAdjustmentJournalEntry {
    public function handle(InventoryMovement $m): JournalEntry {
        $cid = $m->company_id;
        $valor = round((float) $m->cantidad * (float) $m->costo_unitario, 2);
        $inventario = Cuentas::cuenta($cid, 'inventario');
        $ajuste = Cuentas::cuenta($cid, 'faltantes_sobrantes');

        $e = JournalEntry::create(['company_id' => $cid,
            'numero' => 'AS-' . str_pad((string) (JournalEntry::where('company_id', $cid)->count() + 1), 6, '0', STR_PAD_LEFT),
            'fecha' => $m->fecha, 'concepto' => $m->concepto,
            'origen_type' => $m->getMorphClass(), 'origen_id' => $m->id,
            'total_debe' => $valor, 'total_haber' => $valor, 'estado' => 'pendiente']);

        $lineas = $m->tipo === 'ingreso'
            ? [['account_id' => $inventario->id, 'debe' => $valor, 'haber' => 0],
               ['account_id' => $ajuste->id, 'debe' => 0, 'haber' => $valor]]
            : [['account_id' => $ajuste->id, 'debe' => $valor, 'haber' => 0],
               ['account_id' => $inventario->id, 'debe' => 0, 'haber' => $valor]];

        foreach ($lineas as &$l) { $l['referencia'] = (string) $m->id; }
        $e->lines()->createMany($lineas);

        return $e;
    }
}
