<?php

namespace App\Support;

/**
 * Una factura de compra (el XML del proveedor) trae productos que manejan series y no llegaron las series de cada unidad.
 * El sistema no puede inventarlas, y el stock no puede subir sin ellas: no se importa nada.
 * `lineas()` dice qué productos las necesitan: [['codigo', 'descripcion', 'product_id', 'cantidad', 'series_recibidas'], ...],
 * para que la pantalla muestre el paso de captura y vuelva a mandar el archivo con las series.
 */
class SeriesRequeridas extends \RuntimeException
{
    public function __construct(string $message, private array $lineas)
    {
        parent::__construct($message);
    }

    public function lineas(): array
    {
        return $this->lineas;
    }
}
