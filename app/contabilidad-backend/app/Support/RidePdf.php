<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Convierte los datos del RIDE (los arma `RideBuilder`) en el PDF con la vista `ride.documento`.
 * Lo usan el controlador (descarga) y el envío por correo (adjunto): una sola plantilla y una sola configuración.
 */
final class RidePdf
{
    /** Nombre del archivo: RIDE-001-001-000000123.pdf */
    public static function nombreArchivo(array $datos): string
    {
        return 'RIDE-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $datos['numero']).'.pdf';
    }

    public static function crear(array $datos): DomPdf
    {
        // Con subconjunto de fuentes el PDF pesa unos KB en vez de llevar la fuente completa (más de 1 MB)
        return Pdf::loadView('ride.documento', ['d' => $datos])
            ->setOption(['enable_font_subsetting' => true])
            ->setPaper('a4', 'portrait');
    }

    /** Los bytes del PDF, para adjuntarlo a un correo. */
    public static function bytes(array $datos): string
    {
        return self::crear($datos)->output();
    }
}
