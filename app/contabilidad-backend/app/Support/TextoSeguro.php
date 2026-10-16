<?php

namespace App\Support;

/**
 * Limpia un texto antes de guardarlo en la bitácora o de mostrarlo: quita cualquier secreto, lo deja en una línea y lo recorta.
 * Los errores del servidor de correo vienen en inglés y a veces repiten lo que se envió; la clave SMTP nunca debe salir en uno.
 */
final class TextoSeguro
{
    public const MAXIMO = 300;

    /**
     * @param  array<int, ?string>  $secretos  textos que no pueden aparecer (la clave SMTP, por ejemplo)
     */
    public static function limpiar(string $texto, array $secretos = [], int $maximo = self::MAXIMO): string
    {
        foreach ($secretos as $secreto) {
            $secreto = (string) $secreto;
            if ($secreto !== '') {
                // También la versión en base64 y la codificada para URL, que es como suelen viajar en un AUTH o un DSN
                foreach ([$secreto, base64_encode($secreto), rawurlencode($secreto), urlencode($secreto)] as $variante) {
                    $texto = str_replace($variante, '***', $texto);
                }
            }
        }
        // usuario:clave@servidor en cualquier DSN o URL
        $texto = (string) preg_replace('~(://[^/\s:@]+):[^@\s/]+@~u', '$1:***@', $texto);
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));

        return mb_strlen($texto) > $maximo ? rtrim(mb_substr($texto, 0, $maximo - 1)).'…' : $texto;
    }
}
