<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

/**
 * Número del siguiente comprobante de una empresa con prefijo propio: CI-000001 (ingreso de caja), CE- (egreso),
 * DP- (depósito), MB- (movimiento de banco), LQ- (liquidación de tarjetas).
 *
 * Cada empresa lleva su propia cuenta por prefijo, sin tocar el secuencial de la empresa (que es el de las facturas).
 * Se toma del MAYOR número usado + 1 y no de cuántos hay. La fila de la empresa se bloquea (dentro de la transacción de quien
 * llama) para que dos registros a la vez no tomen el mismo número; el índice único (empresa, número) es la última red.
 */
final class NumeroDocumento
{
    /** @param  Builder  $consulta  los registros de esa empresa que llevan la columna `numero` */
    public static function siguiente(int $companyId, Builder $consulta, string $prefijo): string
    {
        Company::whereKey($companyId)->lockForUpdate()->value('id');

        // Los números van rellenos con ceros: el más largo y, entre iguales, el mayor texto es el mayor número
        $ultimo = (clone $consulta)->where('numero', 'like', $prefijo.'-%')
            ->orderByRaw('LENGTH(numero) DESC')->orderByDesc('numero')->value('numero');
        $n = $ultimo !== null && preg_match('/^'.preg_quote($prefijo, '/').'-(\d+)$/', (string) $ultimo, $m) ? (int) $m[1] : 0;

        return sprintf('%s-%06d', $prefijo, $n + 1);
    }
}
