<?php

namespace App\Support;

use App\Models\Account;
use App\Models\JournalEntryLine;

/**
 * Código siguiente del plan de cuentas (T4.3c): el código libre que sigue a las hermanas de un nivel.
 *
 * El plan sembrado usa un dígito en el nivel 1 y 2 (1, 1.1) y dos desde el nivel 3 (1.1.01). Las cuentas que ya
 * existen en el nivel mandan: el relleno con ceros es el de la hermana más ancha y el número es el MAYOR + 1 (un hueco
 * dejado por una cuenta eliminada no se vuelve a usar). Sin hermanas se usa el ancho por defecto del nivel:
 * 1 dígito (niveles 1 y 2), 2 (niveles 3 y 4) y 3 (nivel 5 en adelante: 1.1.01.01.001).
 */
final class CodigosCuenta
{
    /**
     * @return array{padre:?string,padre_nombre:?string,tipo:?string,codigo:string,nivel:int,ancho:int,hermanas:int,advertencia:?string}
     */
    public static function siguiente(int $companyId, ?string $padre): array
    {
        $padre = trim((string) $padre);
        $cuentaPadre = null;
        if ($padre !== '') {
            if (! preg_match('/^\d+(\.\d+)*$/', $padre)) {
                abort(422, 'El código de la cuenta padre solo lleva números separados por puntos, por ejemplo 1.1.01.');
            }
            $cuentaPadre = Account::where('company_id', $companyId)->where('codigo', $padre)->first();
            if (! $cuentaPadre) {
                abort(422, "La cuenta padre {$padre} no existe en este plan de cuentas.");
            }
        }

        $prefijo = $padre === '' ? '' : $padre.'.';
        $codigos = Account::where('company_id', $companyId)->pluck('codigo')->map(fn ($c) => (string) $c)->all();

        // Las hermanas son las hijas directas del padre: su código es el prefijo más un solo tramo
        $tramos = [];
        foreach ($codigos as $c) {
            if ($prefijo !== '' && ! str_starts_with($c, $prefijo)) {
                continue;
            }
            $resto = substr($c, strlen($prefijo));
            if ($resto !== '' && ! str_contains($resto, '.')) {
                $tramos[] = $resto;
            }
        }
        $numericos = array_values(array_filter($tramos, fn ($t) => ctype_digit($t) && strlen($t) <= 12));

        $nivel = ($padre === '' ? 0 : substr_count($padre, '.') + 1) + 1;
        $ancho = $numericos ? max(array_map('strlen', $numericos)) : self::anchoPorNivel($nivel);
        $n = $numericos ? max(array_map('intval', $numericos)) : 0;
        do {
            $codigo = $prefijo.str_pad((string) ++$n, $ancho, '0', STR_PAD_LEFT);
        } while (in_array($codigo, $codigos, true));

        $advertencia = null;
        if ($cuentaPadre && JournalEntryLine::where('account_id', $cuentaPadre->id)->exists()) {
            $advertencia = "La cuenta {$cuentaPadre->codigo} {$cuentaPadre->nombre} ya tiene movimientos. Si le agregas subcuentas dejará de admitir asientos: los nuevos irán a la subcuenta.";
        }

        return [
            'padre' => $padre === '' ? null : $padre,
            'padre_nombre' => $cuentaPadre?->nombre,
            'tipo' => $cuentaPadre?->tipo,
            'codigo' => $codigo,
            'nivel' => $nivel,
            'ancho' => $ancho,
            'hermanas' => count($tramos),
            'advertencia' => $advertencia,
        ];
    }

    private static function anchoPorNivel(int $nivel): int
    {
        return $nivel <= 2 ? 1 : ($nivel <= 4 ? 2 : 3);
    }
}
