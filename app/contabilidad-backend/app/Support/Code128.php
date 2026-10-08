<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Código de barras Code 128, en PHP puro (sin librerías) y sin azar: el mismo texto da siempre los mismos bytes.
 *
 * Es el código que lleva el RIDE del SRI para la clave de acceso (49 dígitos). Un texto solo de dígitos va en el juego C
 * (dos dígitos por símbolo); si son impares, el primero va en el juego B y el resto pasa a C. Cualquier otro texto ASCII
 * imprimible (32–126) va todo en el juego B. Se entrega como SVG: el RIDE lo incrusta como imagen en el PDF.
 */
final class Code128
{
    /** Anchos barra/espacio/barra/espacio/barra/espacio de cada símbolo, por valor (0–105). */
    private const PATRONES = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232',
    ];
    private const PARADA = '2331112';
    private const START_B = 104;
    private const START_C = 105;
    private const CODE_C = 99;
    private const ZONA_SILENCIO = 10;   // módulos en blanco a cada lado

    /** Los anchos de cada símbolo, para revisar la tabla (el último es la parada, de 7 elementos). */
    public static function patrones(): array
    {
        return [...self::PATRONES, self::PARADA];
    }

    /** Valores de los símbolos: inicio, datos, dígito verificador y parada (106). */
    public static function simbolos(string $texto): array
    {
        if ($texto === '') {
            throw new InvalidArgumentException('El código de barras necesita un texto.');
        }
        if (ctype_digit($texto)) {
            $n = strlen($texto);
            if ($n === 1) {
                $valores = [self::START_B, ord($texto) - 32];
            } elseif ($n % 2 === 0) {
                $valores = [self::START_C];
                for ($i = 0; $i < $n; $i += 2) {
                    $valores[] = (int) substr($texto, $i, 2);
                }
            } else {
                $valores = [self::START_B, ord($texto[0]) - 32, self::CODE_C];
                for ($i = 1; $i < $n; $i += 2) {
                    $valores[] = (int) substr($texto, $i, 2);
                }
            }
        } else {
            $valores = [self::START_B];
            foreach (str_split($texto) as $c) {
                $o = ord($c);
                if ($o < 32 || $o > 126) {
                    throw new InvalidArgumentException('El código de barras solo admite texto ASCII imprimible.');
                }
                $valores[] = $o - 32;
            }
        }

        $suma = $valores[0];
        foreach (array_slice($valores, 1) as $i => $v) {
            $suma += $v * ($i + 1);
        }
        $valores[] = $suma % 103;
        $valores[] = 106;

        return $valores;
    }

    /** Anchos consecutivos barra/espacio/barra… en módulos, sin la zona en blanco. Es un texto de dígitos, p. ej. "211232…". */
    public static function anchos(string $texto): string
    {
        $anchos = '';
        foreach (self::simbolos($texto) as $v) {
            $anchos .= $v === 106 ? self::PARADA : self::PATRONES[$v];
        }

        return $anchos;
    }

    /** Ancho total en módulos (sin la zona en blanco). */
    public static function modulos(string $texto): int
    {
        return array_sum(array_map('intval', str_split(self::anchos($texto))));
    }

    /** SVG del código: una barra negra por cada elemento impar, con su zona en blanco a los lados. */
    public static function svg(string $texto, int $alto = 40, int $modulo = 2): string
    {
        $x = self::ZONA_SILENCIO;
        $barras = '';
        foreach (str_split(self::anchos($texto)) as $i => $ancho) {
            $ancho = (int) $ancho;
            if ($i % 2 === 0) {
                $barras .= '<rect x="'.($x * $modulo).'" y="0" width="'.($ancho * $modulo).'" height="'.$alto.'"/>';
            }
            $x += $ancho;
        }
        $total = ($x + self::ZONA_SILENCIO) * $modulo;

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$total.'" height="'.$alto.'" viewBox="0 0 '.$total.' '.$alto.'">'
            .'<rect x="0" y="0" width="'.$total.'" height="'.$alto.'" fill="#ffffff"/><g fill="#000000">'.$barras.'</g></svg>';
    }

    /** El SVG como data URI, listo para <img src="…"> (así dompdf lo dibuja sin tocar el disco ni la red). */
    public static function dataUri(string $texto, int $alto = 40, int $modulo = 2): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($texto, $alto, $modulo));
    }
}
