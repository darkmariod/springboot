<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Clase de contribuyente de un cliente o proveedor (afecta las retenciones que se le aplican).
 * Claves guardadas en contacts.clase_contribuyente; el nombre es el que se muestra en pantalla.
 */
final class ClaseContribuyente
{
    public const CLASES = [
        'rise' => 'RISE',
        'emprendedor' => 'Emprendedor',
        'negocio_popular' => 'Negocio popular',
        'otros' => 'Otros',
    ];

    /** @return string[] */
    public static function claves(): array
    {
        return array_keys(self::CLASES);
    }

    /** @return array<int,array{value:string,label:string}> */
    public static function opciones(): array
    {
        return array_map(fn ($clave, $nombre) => ['value' => $clave, 'label' => $nombre], array_keys(self::CLASES), self::CLASES);
    }

    /**
     * Clase que sugiere el régimen que devuelve el catastro del SRI, o null cuando no está claro.
     * "RIMPE" a secas no dice si es emprendedor o negocio popular, y un régimen vacío o desconocido
     * tampoco: en esos casos no se adivina y se deja la clase en blanco para que la elija el usuario.
     */
    public static function sugerida(?string $regimen, ?string $tipoContribuyente = null): ?string
    {
        $regimen = self::normalizar($regimen);
        $texto = trim($regimen.' '.self::normalizar($tipoContribuyente));

        return match (true) {
            str_contains($texto, 'NEGOCIO POPULAR') => 'negocio_popular',
            str_contains($texto, 'EMPRENDEDOR') => 'emprendedor',
            (bool) preg_match('/\bRISE\b/', $texto) => 'rise',
            (bool) preg_match('/\bGENERAL\b/', $regimen) => 'otros',
            default => null,
        };
    }

    private static function normalizar(?string $texto): string
    {
        return mb_strtoupper(trim(Str::ascii((string) $texto)));
    }
}
