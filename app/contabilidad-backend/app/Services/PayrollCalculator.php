<?php
namespace App\Services;
use App\Models\Employee;

class PayrollCalculator {
    public const APORTE_PERSONAL = 0.0945;
    public const APORTE_PATRONAL = 0.1115;
    public const DECIMO_TERCERO  = 0.0833;
    public const FONDOS_RESERVA  = 0.0833;
    public const VACACIONES      = 0.0417;

    /**
     * $extras (todo opcional):
     *  - ingresos que entran a la materia gravada del IESS y a las provisiones, igual que las horas extra de siempre:
     *    horas_extra, comisiones, otros_ingresos (bonos);
     *  - permisos: descuento por permiso no remunerado (sueldo / 30 x días). Son días no trabajados, así que se restan
     *    de la materia gravada (no se paga IESS ni provisiones sobre lo que no se pagó);
     *  - descuentos que bajan el neto sin tocar la materia gravada: prestamos, anticipos, descuentos, consumos.
     */
    public function forEmployee(Employee $e, float $sbu, array $extras = []): array {
        $horasExtra = round((float)($extras['horas_extra'] ?? 0), 2);
        $comisiones = round((float)($extras['comisiones'] ?? 0), 2);
        $otrosIngresos = round((float)($extras['otros_ingresos'] ?? 0), 2);
        $prestamos  = round((float)($extras['prestamos'] ?? 0), 2);
        $anticipos  = round((float)($extras['anticipos'] ?? 0), 2);
        $descuentos = round((float)($extras['descuentos'] ?? 0), 2);
        $consumos   = round((float)($extras['consumos'] ?? 0), 2);

        $sueldo = (float)$e->sueldo;
        $permisos = round(min(max((float)($extras['permisos'] ?? 0), 0), $sueldo), 2);
        $materiaGravada = $sueldo - $permisos + $horasExtra + $comisiones + $otrosIngresos;
        $aportePersonal = round($materiaGravada * self::APORTE_PERSONAL, 2);
        $ingresos = round($sueldo + $horasExtra + $comisiones + $otrosIngresos, 2);
        $egresos  = round($aportePersonal + $permisos + $prestamos + $anticipos + $descuentos + $consumos, 2);

        return [
            'employee_id'      => $e->id,
            'sueldo'           => $sueldo,
            'horas_extra'      => $horasExtra,
            'comisiones'       => $comisiones,
            'otros_ingresos'   => $otrosIngresos,
            'aporte_personal'  => $aportePersonal,
            'permisos'         => $permisos,
            'prestamos'        => $prestamos,
            'anticipos'        => $anticipos,
            'descuentos'       => $descuentos,
            'consumos'         => $consumos,
            'neto'             => round($ingresos - $egresos, 2),
            'aporte_patronal'  => round($materiaGravada * self::APORTE_PATRONAL, 2),
            'decimo_tercero'   => round($materiaGravada * self::DECIMO_TERCERO, 2),
            'decimo_cuarto'    => round($sbu / 12, 2),
            'fondos_reserva'   => $e->fondos_reserva ? round($materiaGravada * self::FONDOS_RESERVA, 2) : 0,
            'vacaciones'       => round($materiaGravada * self::VACACIONES, 2),
        ];
    }

    public function liquidacion(Employee $e, float $sbu, string $fechaSalida): array {
        $salida = \Carbon\Carbon::parse($fechaSalida);
        $ingreso = $e->fecha_ingreso;
        $sueldo = (float)$e->sueldo;
        $inicioD3 = \Carbon\Carbon::create($salida->month >= 12 ? $salida->year : $salida->year - 1, 12, 1);
        $mesesD3 = max(0, $inicioD3->diffInMonths($salida));
        $inicioD4 = \Carbon\Carbon::create($salida->month >= 8 ? $salida->year : $salida->year - 1, 8, 1);
        $mesesD4 = max(0, $inicioD4->diffInMonths($salida));
        $mesesVac = max(0, min(12, $ingreso->diffInMonths($salida) % 12));
        return [
            'dias_trabajados'     => (int) $ingreso->diffInDays($salida),
            'decimo_tercero_prop' => round($sueldo / 12 * $mesesD3, 2),
            'decimo_cuarto_prop'  => round($sbu / 12 * $mesesD4, 2),
            'vacaciones_prop'     => round($sueldo / 24 * $mesesVac, 2),
            'total' => round($sueldo / 12 * $mesesD3 + $sbu / 12 * $mesesD4 + $sueldo / 24 * $mesesVac, 2),
        ];
    }
}
