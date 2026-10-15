<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Permisos y vacaciones de un empleado (T7.1): días hábiles, saldo de vacaciones y descuento de los permisos sin sueldo.
 *
 * SUPUESTOS que la contadora debe validar (no salen de la ley en este código, salen de lo que ya hacía PayrollCalculator):
 *  - Vacaciones ganadas: PayrollCalculator::liquidacion calcula la parte proporcional como sueldo / 24 por mes de servicio y la
 *    provisión mensual VACACIONES es 0.0417 (= 1/24): es decir, media remuneración (15 días) por cada 12 meses de servicio.
 *    Aquí: 15 días por año de servicio = 1,25 días por MES COMPLETO trabajado. No se suman los días extra por antigüedad
 *    (después de 5 años) del Código del Trabajo; si la empresa los reconoce, se ajusta DIAS_POR_ANIO o se agrega aparte.
 *  - Días tomados: suma de los días de los permisos de tipo `vacaciones` en estado `aprobado`.
 *  - Permiso sin sueldo: descuento = sueldo / 30 x días del mes (el mes laboral es de 30 días). Un permiso que cruza de mes reparte
 *    sus días entre los meses según sus días hábiles (lunes a viernes) en cada uno.
 */
final class VacacionesEmpleado
{
    /** Días de vacaciones por año de servicio (ver el supuesto arriba). */
    public const DIAS_POR_ANIO = 15;

    /** Días del mes laboral para el valor del día de sueldo. */
    public const DIAS_DEL_MES = 30;

    /** Un permiso no puede durar más de un año: evita recorrer rangos absurdos día por día. */
    public const MAX_DIAS_CALENDARIO = 366;

    /** Días de lunes a viernes entre dos fechas, ambas incluidas. */
    public static function diasHabiles(CarbonInterface|string $desde, CarbonInterface|string $hasta): int
    {
        $d = Carbon::parse($desde)->startOfDay();
        $h = Carbon::parse($hasta)->startOfDay();
        $n = 0;
        for ($i = 0; $d->lte($h) && $i < self::MAX_DIAS_CALENDARIO + 1; $i++, $d->addDay()) {
            if ($d->isWeekday()) {
                $n++;
            }
        }

        return $n;
    }

    /** Días del permiso que caen dentro de un mes: sus días x (hábiles del tramo en el mes / hábiles totales del permiso). */
    public static function diasEnMes(EmployeeLeave $l, int $anio, int $mes): float
    {
        $inicioMes = Carbon::create($anio, $mes, 1)->startOfDay();
        $finMes = $inicioMes->copy()->endOfMonth()->startOfDay();
        $desde = Carbon::parse($l->fecha_desde)->startOfDay();
        $hasta = Carbon::parse($l->fecha_hasta)->startOfDay();
        if ($hasta->lt($inicioMes) || $desde->gt($finMes)) {
            return 0.0;
        }
        // Todo dentro del mes: son exactamente los días del permiso (también medio día)
        if ($desde->gte($inicioMes) && $hasta->lte($finMes)) {
            return round((float) $l->dias, 2);
        }
        $habilesTotal = self::diasHabiles($desde, $hasta);
        if ($habilesTotal === 0) {
            return 0.0;
        }
        $habilesMes = self::diasHabiles($desde->gt($inicioMes) ? $desde : $inicioMes, $hasta->lt($finMes) ? $hasta : $finMes);

        return round((float) $l->dias * $habilesMes / $habilesTotal, 2);
    }

    /**
     * Permisos sin sueldo (aprobados y no remunerados) con días en ese mes, y lo que se descuenta por cada uno:
     * sueldo / 30 x días del mes. El total no pasa del sueldo.
     *
     * @return array{total:float,items:array<int,array<string,mixed>>}
     */
    public static function descuentoDelMes(Employee $e, int $anio, int $mes): array
    {
        $sueldo = round((float) $e->sueldo, 2);
        $items = [];
        $totalCentavos = 0;
        $permisos = EmployeeLeave::where('company_id', $e->company_id)->where('employee_id', $e->id)
            ->where('estado', 'aprobado')->where('remunerado', false)
            ->orderBy('fecha_desde')->orderBy('id')->get();
        foreach ($permisos as $l) {
            $dias = self::diasEnMes($l, $anio, $mes);
            if ($dias <= 0) {
                continue;
            }
            $valor = (int) round($sueldo / self::DIAS_DEL_MES * $dias * 100);
            $valor = min($valor, (int) round($sueldo * 100) - $totalCentavos);
            if ($valor <= 0) {
                continue;
            }
            $totalCentavos += $valor;
            $items[] = [
                'tipo' => 'permiso', 'id' => $l->id,
                'concepto' => ucfirst($l->tipo).' sin sueldo del '.Carbon::parse($l->fecha_desde)->format('d/m/Y').' al '.Carbon::parse($l->fecha_hasta)->format('d/m/Y'),
                'dias' => $dias, 'monto' => $valor / 100,
            ];
        }

        return ['total' => $totalCentavos / 100, 'items' => $items];
    }

    /**
     * Saldo de vacaciones a una fecha: ganados − tomados. Si es negativo, la persona "debe" días (tomó más de los ganados).
     *
     * @return array{ganados:float,tomados:float,saldo:float,debe:float,meses:int,dias_por_anio:int,al:string}
     */
    public static function saldo(Employee $e, mixed $al = null): array
    {
        $al = $al ? Carbon::parse($al)->startOfDay() : now()->startOfDay();
        $ingreso = Carbon::parse($e->fecha_ingreso)->startOfDay();
        $hasta = $e->fecha_salida ? Carbon::parse($e->fecha_salida)->startOfDay() : $al;
        if ($hasta->gt($al)) {
            $hasta = $al;
        }
        // Meses completos de servicio (el mes en curso cuenta cuando se cumple)
        $meses = $hasta->lt($ingreso) ? 0 : (int) floor($ingreso->diffInMonths($hasta));
        $ganados = round($meses * self::DIAS_POR_ANIO / 12, 2);
        $tomados = round((float) EmployeeLeave::where('company_id', $e->company_id)->where('employee_id', $e->id)
            ->where('tipo', 'vacaciones')->where('estado', 'aprobado')->sum('dias'), 2);
        $saldo = round($ganados - $tomados, 2);

        return [
            'ganados' => $ganados, 'tomados' => $tomados, 'saldo' => $saldo, 'debe' => $saldo < 0 ? round(-$saldo, 2) : 0.0,
            'meses' => $meses, 'dias_por_anio' => self::DIAS_POR_ANIO, 'al' => $al->toDateString(),
        ];
    }
}
