<?php

namespace App\Support;

use App\Models\Bank;
use App\Models\BankMovement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Movimientos de banco que nacen de un pago o cobro hecho por un banco (cheque, transferencia...), para que
 * aparezcan en la conciliación bancaria. Egreso = 'debito' (pagos), ingreso = 'credito' (cobros).
 *
 * No hacen asiento: el asiento del pago ya pasa por la cuenta Bancos. Se crean dentro de la misma
 * transacción del pago, así un 422 deshace todo.
 */
final class MovimientosBanco
{
    public const COBRO = 'cobro';
    public const PAGO = 'pago';

    /**
     * Registra el movimiento si hay banco; sin banco (efectivo, tarjeta...) no hace nada y devuelve null.
     * 422 si el banco no es de la empresa.
     *
     * @param  string  $sentido  self::COBRO (entra dinero) o self::PAGO (sale dinero)
     */
    public static function registrar(
        int $companyId,
        mixed $bankId,
        string $sentido,
        float $monto,
        string $concepto,
        ?string $documento = null,
        ?Model $origen = null,
        mixed $fecha = null,
    ): ?BankMovement {
        if ($bankId === null || $bankId === '' || $monto <= 0) {
            return null;
        }
        if (! Bank::where('company_id', $companyId)->whereKey($bankId)->exists()) {
            throw ValidationException::withMessages(['bank_id' => ['El banco elegido no existe en esta empresa.']]);
        }

        return BankMovement::create([
            'company_id' => $companyId,
            'bank_id' => (int) $bankId,
            'fecha' => self::fecha($fecha),
            'tipo' => $sentido === self::COBRO ? 'credito' : 'debito',
            'monto' => round($monto, 2),
            'concepto' => $concepto,
            'documento' => $documento !== null && trim($documento) !== '' ? trim($documento) : null,
            'conciliado' => false,
            'origen_type' => $origen?->getMorphClass(),
            'origen_id' => $origen?->getKey(),
        ]);
    }

    /** Fecha del movimiento: la indicada si es válida, si no hoy. */
    private static function fecha(mixed $fecha): string
    {
        if ($fecha) {
            try {
                return Carbon::parse($fecha)->toDateString();
            } catch (\Throwable) {
            }
        }

        return now()->toDateString();
    }
}
