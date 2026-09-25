<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Reintenta una operación que chocó contra el bloqueo de escritura de SQLite.
 *
 * Con varias cajas facturando a la vez, SQLite responde "database is locked" al
 * instante en vez de esperar, y sin reintento la venta se pierde en silencio.
 * El busy_timeout no cubre este caso porque la transacción empieza leyendo y
 * después escribe: ahí SQLite no espera, para no arriesgar un interbloqueo.
 *
 * El reintento va SIEMPRE en la operación más externa. Si ya se está dentro de
 * una transacción, reintentar aquí dejaría a medias la de afuera, así que se
 * deja pasar el error para que lo reintente quien la abrió.
 */
trait RetryOnLock
{
    protected function conReintentos(callable $fn, int $intentos = 8)
    {
        if (DB::transactionLevel() > 0) {
            return $fn();   // lo reintenta la operación externa
        }

        for ($i = 1; ; $i++) {
            try {
                return $fn();
            } catch (QueryException $e) {
                if ($i >= $intentos || ! str_contains($e->getMessage(), 'database is locked')) {
                    throw $e;
                }
                usleep(random_int(20_000, 120_000) * $i);   // espera creciente
            }
        }
    }
}
