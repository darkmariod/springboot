<?php

namespace App\Support;

use App\Models\EmployeeMovement;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Qué movimientos de un empleado entran al rol de un mes y cuánto toca descontar de cada uno (T7.1).
 *
 * Reglas:
 *  - Entra todo movimiento PENDIENTE con saldo cuyo primer mes (`aplicar_desde`) ya llegó: lo que no se pudo descontar un mes
 *    (porque el neto no alcanzaba) se arrastra solo al siguiente.
 *  - Anticipo, descuento e ingreso extra: se toma el saldo completo.
 *  - Préstamo: cuotas iguales de monto / cuotas al centavo; la ÚLTIMA absorbe los centavos. Lo que toca en el mes k es el acumulado que
 *    debería llevar pagado (cuota x k; el monto entero desde la última cuota) menos lo ya pagado, sin pasar del saldo. Así una
 *    cuota que no se pudo descontar completa se suma a la del mes siguiente en vez de perderse.
 *  - El saldo baja solo cuando el rol se CIERRA (NominaRol::cerrar), no al generarlo.
 */
final class NominaMovimientos
{
    /** Movimientos de un empleado que le tocan al rol de ese mes, en el orden en que se descuentan (anticipos, préstamos, descuentos) y luego los ingresos. */
    public static function delMes(int $companyId, int $employeeId, int $anio, int $mes): Collection
    {
        $fin = Carbon::create($anio, $mes, 1)->endOfMonth()->toDateString();
        $orden = ['anticipo' => 1, 'prestamo' => 2, 'descuento' => 3, 'ingreso_extra' => 4];

        return EmployeeMovement::where('company_id', $companyId)->where('employee_id', $employeeId)
            ->where('estado', EmployeeMovement::PENDIENTE)->where('saldo', '>', 0)
            ->whereDate('aplicar_desde', '<=', $fin)
            ->orderBy('fecha')->orderBy('id')->get()
            ->filter(fn (EmployeeMovement $m) => self::debidoEnMes($m, $anio, $mes) > 0)
            ->sortBy(fn (EmployeeMovement $m) => $orden[$m->tipo] ?? 9)->values();
    }

    /** Número de cuota que corresponde al mes (1 en el primer mes de descuento); 0 o menos si todavía no empieza. */
    public static function numeroDeCuota(EmployeeMovement $m, int $anio, int $mes): int
    {
        $desde = Carbon::parse($m->aplicar_desde);

        return ($anio * 12 + $mes) - ($desde->year * 12 + $desde->month) + 1;
    }

    /** Lo que se debe descontar (o pagar, si es ingreso extra) de ese movimiento en el rol del mes, antes de aplicar el tope del neto. */
    public static function debidoEnMes(EmployeeMovement $m, int $anio, int $mes): float
    {
        $saldo = (int) round((float) $m->saldo * 100);
        if ($saldo <= 0 || $m->estado !== EmployeeMovement::PENDIENTE) {
            return 0.0;
        }
        $k = self::numeroDeCuota($m, $anio, $mes);
        if ($k < 1) {
            return 0.0;
        }
        if ($m->tipo !== 'prestamo') {
            return $saldo / 100;
        }

        $monto = (int) round((float) $m->monto * 100);
        $cuotas = max(1, (int) $m->cuotas);
        $cuota = (int) round($monto / $cuotas);
        $acumulado = $k >= $cuotas ? $monto : $cuota * $k;
        $pagado = $monto - $saldo;

        return min($saldo, max(0, $acumulado - $pagado)) / 100;
    }

    /** "Cuota 2 de 3" para un préstamo; null para los demás tipos. */
    public static function etiquetaDeCuota(EmployeeMovement $m, int $anio, int $mes): ?string
    {
        if ($m->tipo !== 'prestamo') {
            return null;
        }
        $cuotas = max(1, (int) $m->cuotas);

        return 'Cuota '.min(max(1, self::numeroDeCuota($m, $anio, $mes)), $cuotas).' de '.$cuotas;
    }
}
