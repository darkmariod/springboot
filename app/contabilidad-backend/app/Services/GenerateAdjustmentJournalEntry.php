<?php
namespace App\Services;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\PhysicalCount;
use App\Support\Cuentas;
use App\Support\NumeroAsiento;

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

    /**
     * UN asiento para toda una toma física (T5.2), no uno por producto: lo contado de más sube el inventario
     * (Debe Inventario / Haber Faltantes y sobrantes por el total de sobrantes) y lo que falta lo baja (Debe Faltantes y
     * sobrantes / Haber Inventario por el total de faltantes). Una toma con las dos cosas deja cuatro líneas; con una sola,
     * dos. Va fechado hoy, igual que los movimientos, y el asiento nace del propio PhysicalCount.
     * Si el mes de hoy está cerrado, JournalEntry lo rechaza (422) y la transacción de quien llama deshace todo.
     */
    public function handleTomaFisica(PhysicalCount $toma, float $sobrantes, float $faltantes, bool $hayIngresos, bool $hayEgresos, string $fecha): JournalEntry {
        $cid = $toma->company_id;
        $sobrantes = round($sobrantes, 2);
        $faltantes = round($faltantes, 2);
        $inventario = Cuentas::cuenta($cid, 'inventario');
        $ajuste = Cuentas::cuenta($cid, 'faltantes_sobrantes');
        $total = round($sobrantes + $faltantes, 2);

        $e = JournalEntry::create(['company_id' => $cid,
            'numero' => NumeroAsiento::siguiente((int) $cid),
            'fecha' => $fecha, 'concepto' => 'Toma física ' . $toma->numero,
            'origen_type' => $toma->getMorphClass(), 'origen_id' => $toma->id,
            'total_debe' => $total, 'total_haber' => $total, 'estado' => 'pendiente']);

        $lineas = [];
        if ($hayIngresos) {
            $lineas[] = ['account_id' => $inventario->id, 'debe' => $sobrantes, 'haber' => 0];
            $lineas[] = ['account_id' => $ajuste->id, 'debe' => 0, 'haber' => $sobrantes];
        }
        if ($hayEgresos) {
            $lineas[] = ['account_id' => $ajuste->id, 'debe' => $faltantes, 'haber' => 0];
            $lineas[] = ['account_id' => $inventario->id, 'debe' => 0, 'haber' => $faltantes];
        }
        foreach ($lineas as &$l) { $l['referencia'] = $toma->numero; }
        $e->lines()->createMany($lineas);

        return $e;
    }
}
