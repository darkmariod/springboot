<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Payroll;
use App\Models\PayrollLine;

/**
 * Rol de pagos impreso (T7.1): el individual de un empleado (HTML que dompdf convierte en PDF) y las filas del rol general.
 * Solo arma texto con lo que ya está guardado en la línea del rol y su detalle; no calcula nada nuevo.
 */
final class RolDePagosPdf
{
    public const MESES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public static function periodo(Payroll $p): string
    {
        return self::MESES[(int) $p->mes].' '.$p->anio;
    }

    /**
     * Las filas del rol de un empleado: cada pieza del detalle con su nombre y lo que no esté en el detalle (un rol anterior a T7.1 o
     * lo escrito a mano) como una fila por columna.
     *
     * @return array{ingresos:array<int,array{0:string,1:float}>,egresos:array<int,array{0:string,1:float}>}
     */
    public static function filas(PayrollLine $l): array
    {
        $detalle = collect($l->detalle ?? []);
        $mapaIngreso = ['horas_extra' => 'Horas extra', 'comisiones' => 'Comisiones', 'otros_ingresos' => 'Otros ingresos (bonos)'];
        $subtipoColumna = ['horas_extra' => 'horas_extra', 'comisiones' => 'comisiones', 'bono' => 'otros_ingresos'];

        $ingresos = [['Sueldo', round((float) $l->sueldo, 2)]];
        foreach ($mapaIngreso as $col => $nombre) {
            $enDetalle = 0.0;
            foreach ($detalle as $it) {
                $colItem = ($it['tipo'] ?? null) === 'ingreso_extra' ? ($subtipoColumna[$it['subtipo'] ?? 'bono'] ?? 'otros_ingresos')
                    : ((($it['tipo'] ?? null) === 'manual') ? ($it['columna'] ?? null) : null);
                if ($colItem === $col && (float) $it['monto'] > 0) {
                    $ingresos[] = [(($it['tipo'] === 'manual') ? $nombre : $nombre.' · '.$it['concepto']), round((float) $it['monto'], 2)];
                    $enDetalle += (float) $it['monto'];
                }
            }
            $resto = round((float) $l->$col - $enDetalle, 2);
            if ($resto > 0.004) {
                $ingresos[] = [$nombre, $resto];
            }
        }

        $egresos = [['Aporte personal IESS '.number_format(\App\Services\PayrollCalculator::APORTE_PERSONAL * 100, 2, ',', '').'%', round((float) $l->aporte_personal, 2)]];
        $grupos = [
            'permisos' => ['Permiso no remunerado', ['permiso']],
            'anticipos' => ['Anticipo', ['anticipo']],
            'prestamos' => ['Préstamo', ['prestamo']],
            'descuentos' => ['Descuento', ['descuento']],
            'consumos' => ['Consumo de la empresa', ['consumo']],
        ];
        foreach ($grupos as $col => [$nombre, $tipos]) {
            $enDetalle = 0.0;
            foreach ($detalle as $it) {
                $esManual = ($it['tipo'] ?? null) === 'manual' && ($it['columna'] ?? null) === $col;
                if ((in_array($it['tipo'] ?? null, $tipos, true) || $esManual) && (float) ($it['monto'] ?? 0) > 0) {
                    $texto = $esManual ? $nombre : ($it['tipo'] === 'consumo' ? 'Consumo de la empresa · factura '.($it['numero'] ?? '') : $nombre.' · '.($it['concepto'] ?? ''));
                    if (! empty($it['cuota'])) {
                        $texto .= ' ('.$it['cuota'].')';
                    }
                    $egresos[] = [$texto, round((float) $it['monto'], 2)];
                    $enDetalle += (float) $it['monto'];
                }
            }
            $resto = round((float) $l->$col - $enDetalle, 2);
            if ($resto > 0.004) {
                $egresos[] = [$nombre, $resto];
            }
        }

        return ['ingresos' => $ingresos, 'egresos' => $egresos];
    }

    public static function html(Payroll $p, PayrollLine $l, ?string $elaboradoPor = null): string
    {
        $empresa = Company::find($p->company_id);
        $emp = $l->employee;
        $e = fn (?string $t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        $money = fn (float $n) => '$ '.number_format($n, 2, '.', ',');
        $filas = self::filas($l);
        $totalIngresos = round(array_sum(array_column($filas['ingresos'], 1)), 2);
        $totalEgresos = round(array_sum(array_column($filas['egresos'], 1)), 2);
        $neto = round((float) $l->neto, 2);
        $logo = $empresa && str_starts_with((string) $empresa->logo, 'data:image/') ? '<img src="'.$e($empresa->logo).'" style="max-height:48px; margin-bottom:4px;"><br>' : '';

        $tabla = function (string $titulo, array $items, float $total) use ($e, $money): string {
            $h = '<table class="rol"><thead><tr><th>'.$e($titulo).'</th><th class="n">Valor</th></tr></thead><tbody>';
            foreach ($items as [$nombre, $valor]) {
                $h .= '<tr><td>'.$e($nombre).'</td><td class="n">'.$money($valor).'</td></tr>';
            }

            return $h.'<tr class="total"><td>Total '.$e(mb_strtolower($titulo)).'</td><td class="n">'.$money($total).'</td></tr></tbody></table>';
        };

        $diferido = round((float) $l->diferido, 2);
        $nota = $diferido > 0
            ? '<p class="nota">Por el tope del neto a pagar, '.$money($diferido).' de descuentos quedan pendientes y se descontarán en el rol del mes siguiente.</p>'
            : '';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1f2937; }
            .cab { text-align: center; margin-bottom: 12px; }
            .cab h1 { font-size: 16px; margin: 0; } .cab p { margin: 1px 0; font-size: 11px; color: #4b5563; }
            h2 { text-align: center; font-size: 15px; margin: 12px 0 2px; letter-spacing: .5px; }
            .per { text-align: center; font-size: 12px; margin-bottom: 12px; color: #374151; }
            table.datos { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            table.datos td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
            table.datos td.k { width: 120px; color: #6b7280; font-size: 11px; text-transform: uppercase; }
            table.rol { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            table.rol th { background: #e8eef7; text-align: left; padding: 5px 8px; font-size: 11px; }
            table.rol td { padding: 4px 8px; border-bottom: 1px solid #e5e7eb; }
            .n { text-align: right; width: 110px; }
            tr.total td { font-weight: bold; border-top: 2px solid #374151; }
            .neto { border: 2px solid #111827; padding: 8px 10px; margin-top: 4px; }
            .neto .v { font-size: 20px; font-weight: bold; } .neto .l { font-size: 11px; color: #4b5563; margin-top: 3px; }
            .nota { font-size: 10px; color: #92400e; margin: 6px 0 0; }
            table.firmas { width: 100%; margin-top: 60px; }
            table.firmas td { width: 50%; text-align: center; padding: 0 24px; font-size: 11px; }
            .linea { border-top: 1px solid #111827; padding-top: 4px; }
            </style></head><body>
            <div class="cab">'.$logo.'<h1>'.$e($empresa?->razon_social).'</h1>
              <p>RUC '.$e($empresa?->ruc).'</p><p>'.$e($empresa?->dir_matriz).'</p></div>
            <h2>ROL DE PAGOS INDIVIDUAL</h2>
            <div class="per">Período: '.$e(self::periodo($p)).'</div>
            <table class="datos">
              <tr><td class="k">Empleado</td><td>'.$e($emp?->nombres).'</td></tr>
              <tr><td class="k">Cédula</td><td>'.$e($emp?->cedula).'</td></tr>
              <tr><td class="k">Cargo</td><td>'.$e($emp?->cargo ?: '—').'</td></tr>
            </table>'
            .$tabla('Ingresos', $filas['ingresos'], $totalIngresos)
            .$tabla('Egresos', $filas['egresos'], $totalEgresos).'
            <div class="neto"><div>Neto a recibir</div><div class="v">'.$money($neto).'</div>
              <div class="l">Son: '.$e(MontoEnLetras::dolares($neto)).'</div></div>'
            .$nota.'
            <table class="firmas"><tr>
              <td><div class="linea">Empleador'.($elaboradoPor ? '<br>'.$e($elaboradoPor) : '').'</div></td>
              <td><div class="linea">Recibí conforme<br>'.$e($emp?->nombres).' · C.I. '.$e($emp?->cedula).'</div></td>
            </tr></table>
            </body></html>';

        // dompdf usa Helvetica (cp1252) y dibuja "?" donde no hay glifo
        return str_replace(['−', '–', '—', '·'], ['-', '-', '-', '-'], $html);
    }
}
