<?php

namespace App\Support;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * Una regla de los períodos contables que impide la acción (cerrar un mes con borradores, reabrir un ejercicio cerrado…).
 * Sale como 422 con `message` en español y, si hace falta, datos para que la pantalla explique el motivo
 * (`bloqueos`, `meses_abiertos`). Se lanza dentro de la transacción: todo lo hecho hasta ahí se deshace.
 */
final class ReglaDePeriodo extends DomainException
{
    /** @param  array<string,mixed>  $extra */
    public function __construct(string $mensaje, public readonly array $extra = [])
    {
        parent::__construct($mensaje);
    }

    /** Es una respuesta de negocio, no un fallo: no ensucia el registro de errores. */
    public function report(): void
    {
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()] + $this->extra, 422);
    }
}
