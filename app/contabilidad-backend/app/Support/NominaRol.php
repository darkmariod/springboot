<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeMovement;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\PaymentSplit;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Services\PayrollCalculator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Arma la línea del rol de cada empleado con todo lo que le toca al mes y cierra el rol con UN asiento (T7.1).
 *
 * Qué entra en la línea de un empleado:
 *  - sus movimientos pendientes (NominaMovimientos): ingresos extra (horas extra, comisiones, bonos), anticipos, cuota del préstamo
 *    y descuentos;
 *  - los permisos sin sueldo del mes (VacacionesEmpleado): sueldo / 30 x días;
 *  - los consumos que la empresa le facturó (facturas abiertas del cliente ligado al empleado), de la más vieja a la más nueva;
 *  - lo que se haya escrito a mano en el parámetro antiguo `extras` (horas_extra, comisiones, prestamos, anticipos...).
 * Los descuentos nunca llevan el neto por debajo de cero: lo que no cabe de un movimiento queda en `diferido` y se arrastra al mes
 * siguiente; lo que no cabe de una factura simplemente sigue abierto. Nada baja de saldo hasta que el rol se CIERRA.
 *
 * El detalle (columna `payroll_lines.detalle`) guarda cada pieza para mostrarla en pantalla, en el rol impreso y para que el cierre
 * sepa qué movimiento y qué factura liquidar.
 */
final class NominaRol
{
    /** Forma de pago con que se registra el descuento de una factura en el rol (rastro en InvoicePayment y PaymentSplit). */
    public const FORMA_DESCUENTO = 'descuento_rol';

    /** Columnas de la línea que se pueden escribir a mano con el parámetro `extras`. */
    public const MANUALES = ['horas_extra', 'comisiones', 'otros_ingresos', 'prestamos', 'anticipos', 'descuentos'];

    /** Columna de ingresos a la que va cada subtipo de ingreso extra. Todas entran a la materia gravada, como las horas extra de siempre. */
    private const COLUMNA_INGRESO = ['horas_extra' => 'horas_extra', 'comisiones' => 'comisiones', 'bono' => 'otros_ingresos'];

    /** Columna de descuentos a la que va cada tipo de movimiento. */
    private const COLUMNA_DESCUENTO = ['anticipo' => 'anticipos', 'prestamo' => 'prestamos', 'descuento' => 'descuentos'];

    /** Facturas abiertas del cliente ligado al empleado hasta el fin del mes del rol, la más antigua primero. */
    public static function consumosAbiertos(Employee $e, int $anio, int $mes): Collection
    {
        if (! $e->contact_id) {
            return collect();
        }
        $fin = Carbon::create($anio, $mes, 1)->endOfMonth()->toDateString();

        return Invoice::where('company_id', $e->company_id)->where('contact_id', $e->contact_id)->soloFacturas()
            ->where('estado', '!=', 'anulado')->where('saldo_pendiente', '>', 0)
            ->whereDate('fecha_emision', '<=', $fin)
            ->orderBy('fecha_emision')->orderBy('id')->get();
    }

    /**
     * Columnas de la línea (listas para PayrollLine::create) y su detalle.
     *
     * @param  array<string,float|int|string>  $manual  claves de self::MANUALES
     * @param  int[]  $excluirFacturas  facturas que NO se descuentan en este rol
     */
    public static function lineaDe(Payroll $p, Employee $e, float $sbu, array $manual = [], array $excluirFacturas = []): array
    {
        $calc = app(PayrollCalculator::class);
        $anio = (int) $p->anio;
        $mes = (int) $p->mes;
        $extras = ['horas_extra' => 0.0, 'comisiones' => 0.0, 'otros_ingresos' => 0.0, 'prestamos' => 0.0, 'anticipos' => 0.0,
            'descuentos' => 0.0, 'consumos' => 0.0, 'permisos' => 0.0];
        $detalle = [];

        foreach (self::MANUALES as $columna) {
            $valor = round(max(0.0, (float) ($manual[$columna] ?? 0)), 2);
            if ($valor > 0) {
                $extras[$columna] = $valor;
                $detalle[] = ['tipo' => 'manual', 'columna' => $columna, 'concepto' => 'Ingresado a mano', 'monto' => $valor];
            }
        }

        $movimientos = NominaMovimientos::delMes((int) $p->company_id, (int) $e->id, $anio, $mes);

        // Ingresos extra: entran completos (horas extra, comisiones y bonos pagan IESS y provisiones, como las horas extra de siempre)
        foreach ($movimientos->where('tipo', 'ingreso_extra') as $m) {
            $columna = self::COLUMNA_INGRESO[$m->subtipo ?? 'bono'] ?? 'otros_ingresos';
            $valor = NominaMovimientos::debidoEnMes($m, $anio, $mes);
            $extras[$columna] = round($extras[$columna] + $valor, 2);
            $detalle[] = ['tipo' => 'ingreso_extra', 'subtipo' => $m->subtipo, 'id' => $m->id, 'concepto' => $m->concepto, 'monto' => $valor];
        }

        $permisos = VacacionesEmpleado::descuentoDelMes($e, $anio, $mes);
        $extras['permisos'] = $permisos['total'];
        foreach ($permisos['items'] as $item) {
            $detalle[] = $item;
        }

        // Lo disponible para descontar: neto después del IESS, de los permisos y de lo escrito a mano; nunca menos de cero
        $base = $calc->forEmployee($e, $sbu, $extras);
        $disponible = max(0, (int) round($base['neto'] * 100));
        $diferido = 0;

        foreach ($movimientos->where('tipo', '!=', 'ingreso_extra') as $m) {
            $debido = (int) round(NominaMovimientos::debidoEnMes($m, $anio, $mes) * 100);
            $aplica = min($debido, $disponible);
            $disponible -= $aplica;
            $diferido += $debido - $aplica;
            $columna = self::COLUMNA_DESCUENTO[$m->tipo];
            $extras[$columna] = round($extras[$columna] + $aplica / 100, 2);
            $detalle[] = ['tipo' => $m->tipo, 'id' => $m->id, 'concepto' => $m->concepto, 'entregado' => (bool) $m->entregado,
                'cuota' => NominaMovimientos::etiquetaDeCuota($m, $anio, $mes),
                'debido' => $debido / 100, 'monto' => $aplica / 100, 'diferido' => ($debido - $aplica) / 100];
        }

        foreach (self::consumosAbiertos($e, $anio, $mes) as $f) {
            $incluido = ! in_array((int) $f->id, array_map('intval', $excluirFacturas), true);
            $saldo = (int) round((float) $f->saldo_pendiente * 100);
            $aplica = $incluido ? min($saldo, $disponible) : 0;
            $disponible -= $aplica;
            $extras['consumos'] = round($extras['consumos'] + $aplica / 100, 2);
            $detalle[] = ['tipo' => 'consumo', 'invoice_id' => $f->id, 'numero' => $f->numero,
                'fecha' => optional($f->fecha_emision)->format('Y-m-d'), 'concepto' => 'Consumo de la empresa, factura '.$f->numero,
                'saldo' => $saldo / 100, 'monto' => $aplica / 100, 'incluido' => $incluido];
        }

        $linea = $calc->forEmployee($e, $sbu, $extras);
        $linea['diferido'] = $diferido / 100;
        $linea['detalle'] = $detalle;

        return $linea;
    }

    /** Vuelve a armar la línea de un empleado con otra elección de facturas (las escritas a mano se conservan). */
    public static function recalcular(Payroll $p, PayrollLine $linea, array $excluirFacturas): PayrollLine
    {
        $e = Employee::findOrFail($linea->employee_id);
        $sbu = (float) (Company::find($p->company_id)?->sbu ?? 470);
        $manual = [];
        foreach ($linea->detalle ?? [] as $item) {
            if (($item['tipo'] ?? null) === 'manual') {
                $manual[$item['columna']] = ($manual[$item['columna']] ?? 0) + (float) $item['monto'];
            }
        }
        $linea->update(self::lineaDe($p, $e, $sbu, $manual, $excluirFacturas));
        self::totales($p);

        return $linea->fresh('employee:id,nombres,cedula,cargo');
    }

    /** Totales de cabecera del rol a partir de sus líneas (en centavos, sin arrastrar decimales). */
    public static function totales(Payroll $p): void
    {
        $l = $p->lines()->get();
        $c = fn (string ...$cols) => $l->sum(fn ($x) => array_sum(array_map(fn ($k) => self::centavos($x->$k), $cols)));
        $p->update([
            'total_ingresos' => ($c('sueldo', 'horas_extra', 'comisiones', 'otros_ingresos')) / 100,
            'total_egresos' => ($c('aporte_personal', 'permisos', 'prestamos', 'anticipos', 'descuentos', 'consumos')) / 100,
            'total_neto' => ($c('neto')) / 100,
            'total_provisiones' => ($c('aporte_patronal', 'decimo_tercero', 'decimo_cuarto', 'fondos_reserva', 'vacaciones')) / 100,
        ]);
    }

    /**
     * Cierra el rol: aplica los movimientos y las facturas descontadas y publica UN asiento balanceado. Debe correr dentro de una
     * transacción: cualquier 422 (un movimiento que cambió, una factura sin saldo, un mes contable cerrado) deshace todo.
     *
     * Asiento (cada línea solo si su valor es mayor que cero):
     *   Debe  Sueldos y salarios (sueldo + horas extra + comisiones + bonos − permisos sin sueldo)
     *   Debe  Aporte patronal IESS · Debe Beneficios sociales (provisiones)
     *   Haber IESS por pagar (personal + patronal) · Haber Beneficios sociales por pagar
     *   Haber Anticipos y préstamos a empleados (lo descontado de movimientos cuyo dinero entregó la empresa por el sistema)
     *   Haber Descuentos a empleados (otros descuentos, y lo escrito a mano o sin entrega registrada)
     *   Haber CxC de cada factura descontada (la cuenta en que nació: normal o de relacionadas), una línea por cuenta
     *   Haber Sueldos por pagar (el neto que se paga)
     * Fecha del asiento: el fin del mes del rol, o hoy si ese mes todavía no termina.
     */
    public static function cerrar(Payroll $payroll): JournalEntry
    {
        $p = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();
        $periodo = sprintf('%02d/%d', $p->mes, $p->anio);
        if ($p->estado === 'cerrado') {
            self::falla("El rol de pagos de $periodo ya está cerrado.");
        }
        $lineas = $p->lines()->get();
        if ($lineas->isEmpty()) {
            self::falla("El rol de pagos de $periodo no tiene empleados. Genéralo antes de cerrarlo.");
        }

        $fecha = Carbon::create($p->anio, $p->mes, 1)->endOfMonth()->startOfDay();
        if ($fecha->gt(now()->startOfDay())) {
            $fecha = now()->startOfDay();
        }

        $entregados = 0;          // descontado de anticipos y préstamos cuyo dinero salió por el sistema (1.1.11)
        $porCxc = [];             // concepto de CxC => ['centavos' => int, 'refs' => string[]]
        $consumosAplicados = 0;
        foreach ($lineas as $linea) {
            foreach ($linea->detalle ?? [] as $item) {
                $tipo = $item['tipo'] ?? null;
                if (in_array($tipo, ['anticipo', 'prestamo', 'descuento', 'ingreso_extra'], true)) {
                    $monto = self::centavos($item['monto'] ?? 0);
                    if ($monto <= 0) {
                        continue;
                    }
                    $m = EmployeeMovement::where('company_id', $p->company_id)->whereKey($item['id'] ?? 0)->lockForUpdate()->first();
                    if (! $m || (int) $m->employee_id !== (int) $linea->employee_id || $m->estado !== EmployeeMovement::PENDIENTE
                        || self::centavos($m->saldo) < $monto) {
                        self::falla('«'.($item['concepto'] ?? 'Un movimiento').'» cambió desde que se generó el rol (se canceló o ya se aplicó). Vuelve a generar el rol de '.$periodo.'.');
                    }
                    $saldo = self::centavos($m->saldo) - $monto;
                    $m->update(['saldo' => $saldo / 100, 'estado' => $saldo <= 0 ? EmployeeMovement::APLICADO : EmployeeMovement::PENDIENTE]);
                    if ($m->entregado && in_array($m->tipo, ['anticipo', 'prestamo'], true)) {
                        $entregados += $monto;
                    }
                } elseif ($tipo === 'consumo' && ($item['incluido'] ?? false) && self::centavos($item['monto'] ?? 0) > 0) {
                    $monto = self::centavos($item['monto']);
                    $f = Invoice::where('company_id', $p->company_id)->whereKey($item['invoice_id'] ?? 0)->lockForUpdate()->first();
                    if (! $f || $f->estado === 'anulado' || (int) $f->contact_id !== (int) Employee::whereKey($linea->employee_id)->value('contact_id')
                        || self::centavos($f->saldo_pendiente) < $monto) {
                        self::falla('La factura '.($item['numero'] ?? '').' ya no tiene saldo para descontarla (se cobró, se anuló o cambió). Vuelve a generar el rol de '.$periodo.'.');
                    }
                    // Concepto de CxC leído ANTES de bajar el saldo: es el del asiento con que nació la factura
                    $concepto = Cuentas::cxcDe($f);
                    // En centavos enteros: restar decimales con coma flotante deja saldos como 175.59999999999997
                    $f->update(['saldo_pendiente' => (self::centavos($f->saldo_pendiente) - $monto) / 100]);
                    InvoicePayment::create(['invoice_id' => $f->id, 'fecha' => $fecha->toDateString(), 'monto' => $monto / 100,
                        'forma_pago' => self::FORMA_DESCUENTO, 'nota' => "Descontado en el rol de pagos $periodo"]);
                    PaymentSplit::create(['company_id' => $p->company_id, 'pagable_type' => $f->getMorphClass(), 'pagable_id' => $f->getKey(),
                        'tipo' => self::FORMA_DESCUENTO, 'fecha' => $fecha->toDateString(), 'valor' => $monto / 100,
                        'documento' => sprintf('ROL-%d-%02d', $p->anio, $p->mes), 'detalle' => "Descontado en el rol de pagos $periodo"]);
                    $porCxc[$concepto]['centavos'] = ($porCxc[$concepto]['centavos'] ?? 0) + $monto;
                    $porCxc[$concepto]['refs'][] = $f->numero;
                    $consumosAplicados += $monto;
                }
            }
        }

        $suma = fn (string ...$cols) => $lineas->sum(fn ($x) => array_sum(array_map(fn ($k) => self::centavos($x->$k), $cols)));
        $sueldos = $suma('sueldo', 'horas_extra', 'comisiones', 'otros_ingresos') - $suma('permisos');
        $personal = $suma('aporte_personal');
        $patronal = $suma('aporte_patronal');
        $provisiones = $suma('decimo_tercero', 'decimo_cuarto', 'fondos_reserva', 'vacaciones');
        $neto = $suma('neto');
        $descuentos = $suma('prestamos', 'anticipos', 'descuentos', 'consumos');
        if ($suma('consumos') !== $consumosAplicados) {
            self::falla("El detalle de consumos del rol de $periodo no coincide con sus valores. Vuelve a generar el rol.");
        }
        $otrosDescuentos = $descuentos - $entregados - $consumosAplicados;
        if ($otrosDescuentos < 0) {
            self::falla("El detalle de descuentos del rol de $periodo no coincide con sus valores. Vuelve a generar el rol.");
        }

        $filas = [];
        $debe = function (string $concepto, int $centavos) use (&$filas, $p) {
            if ($centavos > 0) {
                $filas[] = AsientoCajaBanco::debe(Cuentas::cuenta((int) $p->company_id, $concepto), $centavos / 100, 'ROL');
            }
        };
        $haber = function (string $concepto, int $centavos, string $ref = 'ROL') use (&$filas, $p) {
            if ($centavos > 0) {
                $filas[] = AsientoCajaBanco::haber(Cuentas::cuenta((int) $p->company_id, $concepto), $centavos / 100, mb_substr($ref, 0, 250));
            }
        };
        $debe('sueldos', $sueldos);
        $debe('aporte_patronal', $patronal);
        $debe('beneficios_sociales', $provisiones);
        $haber('iess_por_pagar', $personal + $patronal);
        $haber('beneficios_por_pagar', $provisiones);
        $haber('anticipos_empleados', $entregados);
        $haber('descuentos_empleados', $otrosDescuentos);
        foreach ($porCxc as $concepto => $x) {
            $haber($concepto, $x['centavos'], implode(', ', $x['refs']));
        }
        $haber('sueldos_por_pagar', $neto);

        $asiento = AsientoCajaBanco::publicar((int) $p->company_id, $fecha, "Rol de pagos {$p->mes}/{$p->anio}", $p, $filas);
        $p->update(['estado' => 'cerrado']);

        return $asiento;
    }

    public static function centavos(mixed $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    private static function falla(string $mensaje): never
    {
        throw ValidationException::withMessages(['payroll' => [$mensaje]]);
    }
}
