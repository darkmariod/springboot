<?php

namespace App\Support;

use App\Models\EmailLog;

/**
 * Lo que devuelve el envío de un comprobante por correo: `estado` (enviado | error | omitido) y un `mensaje` legible
 * para mostrarlo tal cual en pantalla. `log` es la fila de la bitácora, si el intento llegó a registrarse.
 */
final class ResultadoCorreo
{
    public function __construct(
        public readonly string $estado,
        public readonly string $mensaje,
        public readonly array $destinatarios = [],
        public readonly ?EmailLog $log = null,
    ) {}

    public function enviado(): bool
    {
        return $this->estado === EmailLog::ENVIADO;
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->enviado(),
            'estado' => $this->estado,
            'mensaje' => $this->mensaje,
            'destinatarios' => $this->destinatarios,
            'correo' => $this->log,
        ];
    }
}
