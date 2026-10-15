<?php

namespace App\Support;

/**
 * Monto en letras para los comprobantes, en español y en mayúsculas: 120.50 -> "CIENTO VEINTE CON 50/100 DÓLARES".
 */
final class MontoEnLetras
{
    private const BASE = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE',
        'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIUNO', 'VEINTIDÓS',
        'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE'];
    private const DECENAS = [3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA', 7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA'];
    private const CENTENAS = [1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS',
        6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS'];

    /** "CIENTO VEINTE CON 50/100 DÓLARES". Los centavos se redondean; un monto negativo se imprime en positivo. */
    public static function dolares(float|int|string $monto): string
    {
        $centavos = (int) round(abs((float) $monto) * 100);
        $enteros = intdiv($centavos, 100);

        return sprintf('%s CON %02d/100 DÓLARES', $enteros === 0 ? 'CERO' : self::entero($enteros), $centavos % 100);
    }

    /** Un entero hasta el billón, en letras ("UNO" al final, "UN" delante de MIL y MILLÓN). */
    private static function entero(int $n): string
    {
        if ($n >= 1000000) {
            $millones = intdiv($n, 1000000);
            $resto = $n % 1000000;
            $texto = $millones === 1 ? 'UN MILLÓN' : self::apocopar(self::entero($millones)).' MILLONES';

            return $resto ? $texto.' '.self::entero($resto) : $texto;
        }
        if ($n >= 1000) {
            $miles = intdiv($n, 1000);
            $resto = $n % 1000;
            $texto = $miles === 1 ? 'MIL' : self::apocopar(self::entero($miles)).' MIL';

            return $resto ? $texto.' '.self::entero($resto) : $texto;
        }
        if ($n === 100) {
            return 'CIEN';
        }
        if ($n >= 100) {
            $resto = $n % 100;

            return self::CENTENAS[intdiv($n, 100)].($resto ? ' '.self::entero($resto) : '');
        }
        if ($n < 30) {
            return self::BASE[$n];
        }
        $unidad = $n % 10;

        return self::DECENAS[intdiv($n, 10)].($unidad ? ' Y '.self::BASE[$unidad] : '');
    }

    /** Delante de MIL o MILLONES el uno pierde la O: "VEINTIÚN MIL", "TREINTA Y UN MIL". */
    private static function apocopar(string $texto): string
    {
        if (str_ends_with($texto, 'VEINTIUNO')) {
            return substr($texto, 0, -9).'VEINTIÚN';
        }

        return str_ends_with($texto, ' UNO') ? substr($texto, 0, -1) : $texto;
    }
}
