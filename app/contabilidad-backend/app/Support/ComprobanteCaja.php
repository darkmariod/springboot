<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CashMovement;

/**
 * HTML del comprobante de ingreso o egreso de caja (T5.3), que dompdf convierte en PDF. Solo arma texto: el número, la
 * contrapartida y el monto salen del propio movimiento y el monto en letras de MontoEnLetras.
 */
final class ComprobanteCaja
{
    public static function html(CashMovement $mov, ?string $elaboradoPor = null): string
    {
        $empresa = Company::find($mov->empresaId());
        $esIngreso = $mov->tipo === 'ingreso';
        $esDeposito = str_starts_with((string) $mov->numero, 'DP-');
        $titulo = $esDeposito ? 'Comprobante de egreso de caja · depósito a banco' : ($esIngreso ? 'Comprobante de ingreso de caja' : 'Comprobante de egreso de caja');
        $contra = $mov->cuenta;
        $fecha = ($mov->fecha ?? $mov->created_at)?->format('d/m/Y') ?? '';
        $monto = round((float) $mov->monto, 2);

        $e = fn (?string $t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        $logo = $empresa && str_starts_with((string) $empresa->logo, 'data:image/') ? '<img src="'.$e($empresa->logo).'" style="max-height:48px; margin-bottom:4px;"><br>' : '';
        $cuenta = $contra ? $e($contra->codigo.' · '.$contra->nombre) : '—';
        $numero = $mov->numero ? $e($mov->numero) : 'Sin número (movimiento anterior)';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1f2937; }
            .cab { text-align: center; margin-bottom: 14px; }
            .cab h1 { font-size: 16px; margin: 0; } .cab p { margin: 1px 0; font-size: 11px; color: #4b5563; }
            h2 { text-align: center; font-size: 15px; margin: 14px 0 4px; letter-spacing: .5px; }
            .num { text-align: center; font-size: 13px; font-weight: bold; margin-bottom: 14px; }
            table.datos { width: 100%; border-collapse: collapse; }
            table.datos td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
            table.datos td.k { width: 150px; color: #6b7280; font-size: 11px; text-transform: uppercase; }
            .monto { font-size: 20px; font-weight: bold; }
            .letras { font-weight: bold; }
            table.firmas { width: 100%; margin-top: 70px; }
            table.firmas td { width: 50%; text-align: center; padding: 0 24px; font-size: 11px; }
            .linea { border-top: 1px solid #111827; padding-top: 4px; }
            </style></head><body>
            <div class="cab">'.$logo.'<h1>'.$e($empresa?->razon_social).'</h1>
              <p>RUC '.$e($empresa?->ruc).'</p><p>'.$e($empresa?->dir_matriz).'</p></div>
            <h2>'.mb_strtoupper($e($titulo)).'</h2>
            <div class="num">N.º '.$numero.'</div>
            <table class="datos">
              <tr><td class="k">Fecha</td><td>'.$e($fecha).'</td></tr>
              <tr><td class="k">Concepto</td><td>'.$e($mov->concepto).'</td></tr>
              <tr><td class="k">Cuenta contrapartida</td><td>'.$cuenta.'</td></tr>
              <tr><td class="k">Valor</td><td class="monto">$ '.number_format($monto, 2, '.', ',').'</td></tr>
              <tr><td class="k">Son</td><td class="letras">'.$e(MontoEnLetras::dolares($monto)).'</td></tr>
            </table>
            <table class="firmas"><tr>
              <td><div class="linea">Elaborado por'.($elaboradoPor ? '<br>'.$e($elaboradoPor) : '').'</div></td>
              <td><div class="linea">Recibido por<br>Nombre y firma</div></td>
            </tr></table>
            </body></html>';

        // dompdf usa Helvetica (cp1252) y dibuja "?" donde no hay glifo
        return str_replace(['−', '–', '—'], ['-', '-', '-'], $html);
    }
}
