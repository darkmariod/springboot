<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Lee el estado de cuenta que baja el banco en CSV (T5.3 / conciliación bancaria). Solo lee: no toca ninguna tabla.
 *
 * Formas que reconoce (el encabezado se busca en las primeras 15 filas sin importar mayúsculas ni tildes):
 *  1. columnas separadas  -> Fecha · Descripción/Detalle/Concepto · Documento/Referencia · Débito/Retiro/Cargo · Crédito/Depósito/Abono
 *  2. valor y tipo        -> Fecha · Descripción · Documento · Valor/Monto/Importe · Tipo (D/C, Débito/Crédito, DB/CR, + / -)
 *  3. valor con signo     -> Fecha · Descripción · Documento · Valor/Monto (negativo = débito, positivo = crédito)
 *  4. sin encabezado      -> exactamente tres columnas: fecha, detalle, valor con signo (el archivo de la primera versión)
 * Separador coma, punto y coma, tabulación o barra vertical (se detecta solo). Fechas dd/mm/aaaa, dd-mm-aaaa, aaaa-mm-dd
 * (y con año de dos cifras). Montos como 1234.56, 1.234,56, 1,234.56 o $ 1 234,56, con paréntesis o guion para los negativos.
 * Archivos en UTF-8 o en Latin-1 (el que suele bajar el banco). Cualquier otra forma se rechaza con un mensaje claro (422).
 */
final class ArchivoBanco
{
    private const SINONIMOS = [
        'fecha' => ['fecha', 'fecha movimiento', 'fecha de movimiento', 'fecha contable', 'fecha operacion', 'fecha de operacion', 'fecha transaccion', 'fecha valor', 'date'],
        'descripcion' => ['descripcion', 'detalle', 'concepto', 'glosa', 'observacion', 'observaciones', 'transaccion', 'movimiento', 'descripcion del movimiento', 'description'],
        'documento' => ['documento', 'no documento', 'nro documento', 'num documento', 'numero documento', 'numero de documento', 'n documento', 'n de documento',
            'referencia', 'ref', 'nro referencia', 'numero de referencia', 'comprobante', 'cheque', 'no cheque', 'numero cheque', 'nro cheque', 'num doc', 'nro doc', 'n doc'],
        'debito' => ['debito', 'debitos', 'debe', 'retiro', 'retiros', 'egreso', 'egresos', 'cargo', 'cargos', 'debit'],
        'credito' => ['credito', 'creditos', 'haber', 'deposito', 'depositos', 'abono', 'abonos', 'ingreso', 'ingresos', 'credit'],
        'valor' => ['valor', 'monto', 'importe', 'amount', 'valor movimiento', 'valor del movimiento'],
        'tipo' => ['tipo', 'tipo movimiento', 'tipo de movimiento', 'tipo transaccion', 'd c', 'dc', 'naturaleza', 'signo', 'd/c'],
    ];

    /**
     * @return array{formato:string,lineas:array<int,array{fila:int,fecha:string,descripcion:string,documento:?string,tipo:string,monto:float}>,ignoradas:array<int,array{fila:int,motivo:string}>}
     */
    public static function leer(string $contenido): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'ISO-8859-1');
        }
        $lineasTexto = preg_split('/\r\n|\r|\n/', $contenido) ?: [];
        $filas = [];
        foreach ($lineasTexto as $i => $texto) {
            if (trim($texto) !== '') {
                $filas[$i + 1] = $texto;
            }
        }
        if (! $filas) {
            self::rechazar('El archivo está vacío.');
        }

        $sep = self::separador(array_slice($filas, 0, 15));
        $celdas = array_map(fn ($t) => array_map('trim', str_getcsv($t, $sep, '"', '')), $filas);

        [$forma, $columnas, $filaEncabezado] = self::buscarEncabezado($celdas);
        if ($forma === null) {
            [$forma, $columnas, $filaEncabezado] = self::sinEncabezado($celdas);
        }
        if ($forma === null) {
            $primera = reset($celdas);
            self::rechazar('No reconozco el formato del archivo. Debe traer una fila de encabezados con "Fecha" y, o bien "Débito" y "Crédito", '
                .'o bien "Valor" (con una columna "Tipo", o con los débitos en negativo). Columnas encontradas: '.implode(', ', array_filter($primera, fn ($c) => $c !== '')).'.');
        }

        $lineas = [];
        $ignoradas = [];
        foreach ($celdas as $numeroFila => $c) {
            if ($filaEncabezado !== null && $numeroFila <= $filaEncabezado) {
                continue;
            }
            $f = self::fila($forma, $columnas, $c);
            if (isset($f['ignorada'])) {
                $ignoradas[] = ['fila' => $numeroFila, 'motivo' => $f['ignorada']];
                continue;
            }
            $lineas[] = ['fila' => $numeroFila] + $f;
        }
        if (! $lineas) {
            self::rechazar('El archivo no tiene movimientos que se puedan leer.'.($ignoradas ? ' Primer problema: fila '.$ignoradas[0]['fila'].', '.$ignoradas[0]['motivo'].'.' : ''));
        }

        return ['formato' => $forma, 'lineas' => $lineas, 'ignoradas' => $ignoradas];
    }

    /** Texto sin tildes, en minúsculas y con espacios simples: "Débito (USD)" -> "debito usd". */
    public static function normalizar(string $t): string
    {
        $t = mb_strtolower(trim($t), 'UTF-8');
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/[^a-z0-9\/]+/', ' ', $t) ?? '');
    }

    /** Número de documento comparable: solo letras y números, sin ceros a la izquierda ("0004512" = "4512"). */
    public static function documentoComparable(?string $d): string
    {
        $limpio = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $d, 'UTF-8')) ?? '';

        return ltrim($limpio, '0');
    }

    /** El separador que más veces aparece en las primeras filas (así un título suelto antes del encabezado no lo confunde). */
    private static function separador(array $filas): string
    {
        $mejor = ',';
        $max = 0;
        foreach ([';', "\t", '|', ','] as $s) {
            $n = array_sum(array_map(fn ($linea) => substr_count($linea, $s), $filas));
            if ($n > $max) {
                $max = $n;
                $mejor = $s;
            }
        }

        return $mejor;
    }

    /** @return array{0:?string,1:array<string,int>,2:?int} */
    private static function buscarEncabezado(array $celdas): array
    {
        $revisadas = 0;
        foreach ($celdas as $numeroFila => $c) {
            if (++$revisadas > 15) {
                break;
            }
            $col = [];
            foreach ($c as $i => $celda) {
                $n = self::normalizar($celda);
                foreach (self::SINONIMOS as $rol => $palabras) {
                    if (! isset($col[$rol]) && in_array($n, $palabras, true)) {
                        $col[$rol] = $i;
                        break;
                    }
                }
            }
            if (! isset($col['fecha'])) {
                continue;
            }
            if (isset($col['debito']) && isset($col['credito'])) {
                return ['columnas débito y crédito', $col, $numeroFila];
            }
            if (isset($col['valor']) && isset($col['tipo'])) {
                return ['valor y tipo', $col, $numeroFila];
            }
            if (isset($col['valor'])) {
                return ['valor con signo', $col, $numeroFila];
            }
        }

        return [null, [], null];
    }

    /** Tres columnas sin encabezado: fecha, detalle y valor con signo. */
    private static function sinEncabezado(array $celdas): array
    {
        $primera = reset($celdas);
        if (count($primera) === 3 && self::fecha($primera[0]) !== null && self::numero($primera[2]) !== null) {
            return ['sin encabezado (fecha, detalle, valor)', ['fecha' => 0, 'descripcion' => 1, 'valor' => 2], null];
        }

        return [null, [], null];
    }

    /** @return array<string,mixed> la línea, o ['ignorada' => motivo] */
    private static function fila(string $forma, array $col, array $c): array
    {
        $celda = fn (string $rol) => isset($col[$rol]) ? ($c[$col[$rol]] ?? '') : '';
        if (! array_filter($c, fn ($x) => $x !== '')) {
            return ['ignorada' => 'fila vacía'];
        }
        $fecha = self::fecha($celda('fecha'));
        if ($fecha === null) {
            return ['ignorada' => 'la fecha "'.$celda('fecha').'" no es válida (se esperaba dd/mm/aaaa)'];
        }

        $tipo = null;
        $monto = null;
        if ($forma === 'columnas débito y crédito') {
            $debito = self::numero($celda('debito'));
            $credito = self::numero($celda('credito'));
            $debito = $debito === null ? 0.0 : abs($debito);
            $credito = $credito === null ? 0.0 : abs($credito);
            if ($debito > 0 && $credito > 0) {
                return ['ignorada' => 'trae valor en Débito y en Crédito a la vez'];
            }
            if ($debito == 0 && $credito == 0) {
                return ['ignorada' => 'no trae valor'];
            }
            [$tipo, $monto] = $credito > 0 ? ['credito', $credito] : ['debito', $debito];
        } else {
            $valor = self::numero($celda('valor'));
            if ($valor === null || $valor == 0) {
                return ['ignorada' => 'el valor "'.$celda('valor').'" no es un monto'];
            }
            if ($forma === 'valor y tipo') {
                $tipo = self::tipo($celda('tipo'));
                if ($tipo === null) {
                    return ['ignorada' => 'el tipo "'.$celda('tipo').'" no es débito ni crédito'];
                }
                $monto = abs($valor);
            } else {
                [$tipo, $monto] = $valor < 0 ? ['debito', abs($valor)] : ['credito', $valor];
            }
        }
        $doc = trim($celda('documento'));

        return [
            'fecha' => $fecha,
            'descripcion' => $celda('descripcion'),
            'documento' => $doc !== '' ? $doc : null,
            'tipo' => $tipo,
            'monto' => round($monto, 2),
        ];
    }

    private static function tipo(string $t): ?string
    {
        $n = self::normalizar($t);
        $crudo = trim($t);
        if ($crudo === '+' || in_array($n, ['c', 'cr', 'credito', 'credit', 'abono', 'deposito', 'ingreso', 'haber'], true)) {
            return 'credito';
        }
        if ($crudo === '-' || in_array($n, ['d', 'db', 'debito', 'debit', 'cargo', 'retiro', 'egreso', 'debe'], true)) {
            return 'debito';
        }

        return null;
    }

    /** Fecha en Y-m-d, o null si el texto no es una fecha. */
    public static function fecha(string $t): ?string
    {
        $t = trim($t);
        if (preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})(?!\d)/', $t, $m)) {
            [$a, $mes, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4}|\d{2})(?!\d)/', $t, $m)) {
            [$d, $mes, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $a = $a < 100 ? 2000 + $a : $a;
        } else {
            return null;
        }

        return checkdate($mes, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $mes, $d) : null;
    }

    /** Monto como número, o null si no es un monto. Entiende 1234.56, 1.234,56, 1,234.56, $ 1 234,56, (50.00) y 50.00-. */
    public static function numero(string $t): ?float
    {
        $t = trim(str_replace(['$', 'USD', ' ', "\u{00A0}"], '', $t));
        if ($t === '' || $t === '-') {
            return null;
        }
        $negativo = false;
        if (preg_match('/^\((.*)\)$/', $t, $m)) {
            $negativo = true;
            $t = $m[1];
        }
        if (str_ends_with($t, '-')) {
            $negativo = true;
            $t = substr($t, 0, -1);
        }
        if (str_starts_with($t, '-')) {
            $negativo = true;
            $t = substr($t, 1);
        }
        if (! preg_match('/^\d[\d.,]*$/', $t)) {
            return null;
        }
        $punto = strrpos($t, '.');
        $coma = strrpos($t, ',');
        if ($punto !== false && $coma !== false) {
            // El último separador es el decimal
            $decimal = $punto > $coma ? '.' : ',';
            $miles = $decimal === '.' ? ',' : '.';
            $t = str_replace($decimal, '#', str_replace($miles, '', $t));
        } elseif ($coma !== false) {
            // Solo comas: "1,5" / "12,50" son decimales; "1,234" o "1,234,567" son miles
            $t = (substr_count($t, ',') === 1 && strlen($t) - $coma - 1 !== 3) ? str_replace(',', '#', $t) : str_replace(',', '', $t);
        } elseif ($punto !== false) {
            // Solo puntos: uno solo es decimal ("1234.56"); varios son miles ("1.234.567")
            $t = substr_count($t, '.') === 1 ? str_replace('.', '#', $t) : str_replace('.', '', $t);
        }
        $valor = (float) str_replace('#', '.', $t);

        return $negativo ? -$valor : $valor;
    }

    private static function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages(['csv' => [$mensaje]]);
    }
}
