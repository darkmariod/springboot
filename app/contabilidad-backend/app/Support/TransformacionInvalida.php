<?php

namespace App\Support;

/**
 * Un fraccionamiento o conversión que no se puede hacer (stock insuficiente, producto con series...).
 * El mensaje va en español para el usuario; $datos son campos extra de la respuesta 422 (p. ej. `disponible`).
 */
class TransformacionInvalida extends \RuntimeException
{
    public function __construct(string $message, public readonly array $datos = [])
    {
        parent::__construct($message);
    }
}
