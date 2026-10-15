<?php

namespace App\Support;

use App\Models\BankMovement;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Empareja las líneas del estado de cuenta del banco (ArchivoBanco) con los movimientos del sistema para conciliarlos.
 * Solo propone: no marca nada ni crea asientos. Reglas, en este orden y de a un movimiento por línea:
 *  1. mismo sentido (entra / sale), mismo monto al centavo y el mismo número de documento (sin ceros a la izquierda ni
 *     símbolos), con hasta DIAS_DOCUMENTO días de diferencia (un cheque se cobra días después de emitido);
 *  2. mismo sentido y monto, con hasta DIAS_FECHA días de diferencia; gana la fecha más cercana.
 */
final class ConciliacionArchivo
{
    public const DIAS_DOCUMENTO = 45;
    public const DIAS_FECHA = 3;

    /**
     * @param  array<int,array>  $lineas  líneas de ArchivoBanco::leer
     * @param  Collection<int,BankMovement>  $movimientos  candidatos (sin conciliar y que ya cuentan en el banco)
     * @return array<int,array{movimiento:BankMovement,criterio:string}> por índice de línea
     */
    public static function emparejar(array $lineas, Collection $movimientos): array
    {
        $libres = $movimientos->keyBy('id');
        $pares = [];

        // Pasada 1: por número de documento
        foreach ($lineas as $i => $l) {
            $doc = ArchivoBanco::documentoComparable($l['documento'] ?? null);
            if ($doc === '') {
                continue;
            }
            $elegido = self::masCercano($l, $libres, self::DIAS_DOCUMENTO, $doc);
            if ($elegido) {
                $pares[$i] = ['movimiento' => $elegido, 'criterio' => 'documento'];
                $libres->forget($elegido->id);
            }
        }
        // Pasada 2: por fecha y monto
        foreach ($lineas as $i => $l) {
            if (isset($pares[$i])) {
                continue;
            }
            $elegido = self::masCercano($l, $libres, self::DIAS_FECHA, null);
            if ($elegido) {
                $pares[$i] = ['movimiento' => $elegido, 'criterio' => 'fecha y monto'];
                $libres->forget($elegido->id);
            }
        }

        return $pares;
    }

    private static function masCercano(array $linea, Collection $libres, int $dias, ?string $documento): ?BankMovement
    {
        $centavos = (int) round($linea['monto'] * 100);
        $fecha = Carbon::parse($linea['fecha'])->startOfDay();
        $mejor = null;
        $distanciaMejor = PHP_INT_MAX;
        foreach ($libres as $m) {
            if ($m->tipo !== $linea['tipo'] || (int) round((float) $m->monto * 100) !== $centavos) {
                continue;
            }
            if ($documento !== null && ArchivoBanco::documentoComparable($m->documento) !== $documento) {
                continue;
            }
            $distancia = (int) abs($fecha->diffInDays(Carbon::parse($m->fecha)->startOfDay(), false));
            if ($distancia <= $dias && $distancia < $distanciaMejor) {
                $mejor = $m;
                $distanciaMejor = $distancia;
            }
        }

        return $mejor;
    }
}
