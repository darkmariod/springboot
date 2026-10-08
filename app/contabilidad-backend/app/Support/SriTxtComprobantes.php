<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Lector del TXT de "comprobantes recibidos" que se descarga del portal del SRI.
 *
 * El portal ha cambiado el orden de las columnas con el tiempo (en unas versiones la columna 2 es la razón
 * social del emisor y en otras es la serie), así que:
 *  - Si el archivo trae ENCABEZADO, cada dato se toma por el NOMBRE de su columna (sin importar mayúsculas,
 *    tildes, guiones bajos ni el orden): RUC emisor, razón social emisor, tipo comprobante, serie comprobante,
 *    clave de acceso...
 *  - Si no trae encabezado, se usa el orden de siempre: RUC; razón social; tipo; serie; clave de acceso...
 *    (una columna 2 que parece serie, fecha o número no se guarda como razón social).
 * La clave de acceso se busca en su columna y, si no está ahí, en cualquier parte de la línea (49 dígitos).
 * El tipo de comprobante sale de la CLAVE (dígitos 9 y 10), que es lo único confiable: solo las facturas (01)
 * se pueden importar como compra; las demás quedan "omitidas" con su motivo.
 */
final class SriTxtComprobantes
{
    public const FACTURA = '01';

    /** Tipo de comprobante (dígitos 9 y 10 de la clave) => nombre con su artículo, para los motivos. */
    public const TIPOS = [
        '01' => 'una factura',
        '03' => 'una liquidación de compra',
        '04' => 'una nota de crédito',
        '05' => 'una nota de débito',
        '06' => 'una guía de remisión',
        '07' => 'un comprobante de retención',
    ];

    /** Nombres de columna (ya normalizados) que se reconocen en el encabezado. */
    private const COLUMNAS = [
        'ruc' => ['ruc emisor', 'ruc', 'ruc proveedor', 'identificacion emisor'],
        'razon' => ['razon social emisor', 'razon social', 'emisor', 'nombre emisor', 'proveedor', 'razon social proveedor'],
        'tipo' => ['tipo comprobante', 'tipo de comprobante', 'comprobante', 'tipo'],
        'serie' => ['serie comprobante', 'serie', 'numero comprobante', 'numero documento', 'numero de comprobante'],
        'clave' => ['clave de acceso', 'clave acceso', 'clave'],
    ];

    /**
     * @return array{filas:array<int,array{clave_acceso:string,ruc_emisor:string,razon_social:?string,tipo:string,estado:string,motivo:?string}>,sin_clave:int}
     */
    public static function leer(string $contenido): array
    {
        $contenido = self::aUtf8($contenido);
        $lineas = preg_split('/\r\n|\r|\n/', $contenido);
        $mapa = null;
        $primera = true;
        $filas = [];
        $sinClave = 0;

        foreach ($lineas as $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $celdas = self::celdas($linea);
            // La primera línea con datos puede ser el encabezado: no trae clave y nombra al menos dos columnas conocidas
            if ($primera) {
                $primera = false;
                if (! preg_match('/\d{49}/', $linea) && ($m = self::mapaDeEncabezado($celdas)) !== null) {
                    $mapa = $m;
                    continue;
                }
            }
            $fila = self::fila($linea, $celdas, $mapa);
            if ($fila === null) {
                $sinClave++;
                continue;
            }
            $filas[] = $fila;
        }

        return ['filas' => $filas, 'sin_clave' => $sinClave];
    }

    /** Código del tipo de comprobante que indica la clave de acceso (dígitos 9 y 10): 01 factura, 04 nota de crédito, 07 retención... */
    public static function tipoDeClave(string $clave): string
    {
        return substr($clave, 8, 2);
    }

    private static function fila(string $linea, array $celdas, ?array $mapa): ?array
    {
        // Clave de acceso: su columna si es de 49 dígitos; si no, la primera secuencia de 49 dígitos de la línea
        $clave = null;
        if ($mapa !== null && isset($mapa['clave']) && preg_match('/^\d{49}$/', $celdas[$mapa['clave']] ?? '')) {
            $clave = $celdas[$mapa['clave']];
        } elseif (preg_match('/(?<!\d)(\d{49})(?!\d)/', $linea, $m)) {
            $clave = $m[1];
        }
        if ($clave === null) {
            return null;
        }

        // RUC emisor: el de su columna si es de 13 dígitos; si no, el que va dentro de la clave (dígitos 11 a 23)
        $ruc = substr($clave, 10, 13);
        if ($mapa !== null && isset($mapa['ruc']) && preg_match('/^\d{13}$/', $celdas[$mapa['ruc']] ?? '')) {
            $ruc = $celdas[$mapa['ruc']];
        }

        // Razón social: por encabezado, su columna; sin encabezado, la columna 2 de siempre si parece un nombre
        $razon = $mapa !== null
            ? ($celdas[$mapa['razon'] ?? -1] ?? '')
            : ($celdas[1] ?? '');
        $razon = self::pareceNombre($razon, $clave) ? mb_substr($razon, 0, 250) : null;

        $tipo = self::tipoDeClave($clave);
        $esFactura = $tipo === self::FACTURA;

        return [
            'clave_acceso' => $clave,
            'ruc_emisor' => $ruc,
            'razon_social' => $razon,
            'tipo' => $tipo,
            'estado' => $esFactura ? 'pendiente' : 'omitido',
            'motivo' => $esFactura ? null : self::motivoOmision($tipo),
        ];
    }

    public static function motivoOmision(string $tipo): string
    {
        $nombre = self::TIPOS[$tipo] ?? "un comprobante de tipo $tipo";

        return "Se omitió: es $nombre, no es una factura de compra.";
    }

    /** ¿Es un nombre (y no una serie, un número, una fecha, la clave o el nombre del tipo de comprobante)? */
    private static function pareceNombre(string $texto, string $clave): bool
    {
        $texto = trim($texto);
        if ($texto === '' || ! preg_match('/\pL/u', $texto)) {
            return false; // vacío o sin letras: número, fecha, serie...
        }
        if (preg_match('/^\d{3}-\d{3}-\d{6,9}$/', $texto) || str_contains($texto, $clave)) {
            return false;
        }
        // El nombre del tipo de comprobante ("Factura", "Comprobante de Retención"...) no es una razón social
        $normal = self::normalizar($texto);
        foreach (['factura', 'comprobante de retencion', 'notas de credito', 'nota de credito', 'notas de debito', 'nota de debito',
                     'liquidacion de compra', 'guia de remision', 'retencion', 'comprobante'] as $tipo) {
            if ($normal === $tipo) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,int>|null columna de cada dato reconocido; null si la línea no parece un encabezado */
    private static function mapaDeEncabezado(array $celdas): ?array
    {
        $mapa = [];
        foreach ($celdas as $i => $celda) {
            $nombre = self::normalizar($celda);
            foreach (self::COLUMNAS as $dato => $alias) {
                if (! isset($mapa[$dato]) && in_array($nombre, $alias, true)) {
                    $mapa[$dato] = $i;
                }
            }
        }

        return count($mapa) >= 2 ? $mapa : null;
    }

    /** Minúsculas, sin tildes y con cualquier separador (_ - . /) convertido en un espacio. */
    private static function normalizar(string $texto): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($texto))));
    }

    /** @return array<int,string> */
    private static function celdas(string $linea): array
    {
        return array_map(fn ($c) => trim($c, " \t\"'"), preg_split('/\t|;|\|/', $linea));
    }

    /** El SRI entrega el TXT en Windows-1252 / ISO-8859-1; la base y el JSON van en UTF-8. */
    private static function aUtf8(string $contenido): string
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        return $contenido;
    }
}
