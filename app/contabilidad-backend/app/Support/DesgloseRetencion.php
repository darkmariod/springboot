<?php

namespace App\Support;

use App\Models\Withholding;

/**
 * Cuánto de una retención es de IVA y cuánto de renta. El formulario 104 solo descuenta del IVA las retenciones de IVA.
 *
 * - Retención EMITIDA: cada línea es una fila y `xml` guarda un JSON con su `tipo` ('iva' | 'renta'); las filas anteriores a ese
 *   dato son de renta (así las lee también el formulario 103).
 * - Retención RECIBIDA: una sola fila con el total y el XML original del cliente; el tipo está en el código de cada línea del
 *   comprobante (1 = renta, 2 = IVA, 6 = ISD). Lo que no se pueda clasificar queda en `otras` y no baja ningún impuesto.
 */
class DesgloseRetencion
{
    /** @return array{iva: float, renta: float, otras: float} */
    public static function de(Withholding $w): array
    {
        return $w->tipo === 'emitida' ? self::emitida($w) : self::recibida($w);
    }

    /** @return array{iva: float, renta: float, otras: float} */
    private static function emitida(Withholding $w): array
    {
        $total = round((float) $w->total_retenido, 2);
        $tipo = (json_decode((string) $w->xml, true)['tipo'] ?? 'renta');

        return $tipo === 'iva'
            ? ['iva' => $total, 'renta' => 0.0, 'otras' => 0.0]
            : ['iva' => 0.0, 'renta' => $total, 'otras' => 0.0];
    }

    /** @return array{iva: float, renta: float, otras: float} */
    private static function recibida(Withholding $w): array
    {
        $total = round((float) $w->total_retenido, 2);
        $iva = 0.0;
        $renta = 0.0;
        foreach (self::lineas((string) $w->xml) as [$codigo, $valor]) {
            if ($codigo === '2') {
                $iva += $valor;
            } elseif ($codigo === '1') {
                $renta += $valor;
            }
        }
        $iva = round($iva, 2);
        $renta = round($renta, 2);

        // Lo que el XML no explica (ISD, un XML ilegible, un total que no cuadra) no es IVA ni renta comprobada
        return ['iva' => $iva, 'renta' => $renta, 'otras' => round(max(0.0, $total - $iva - $renta), 2)];
    }

    /**
     * [código del impuesto, valor retenido] de cada línea del comprobante, sea el XML 1.0 (impuestos/impuesto) o el 2.0
     * (docsSustento/…/retenciones/retencion), con o sin el sobre de autorización del SRI.
     *
     * @return list<array{0:string,1:float}>
     */
    private static function lineas(string $contenido): array
    {
        if (trim($contenido) === '') {
            return [];
        }
        $anterior = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contenido);
            if ($xml !== false && $xml->getName() !== 'comprobanteRetencion') {
                // Sobre de autorización: el comprobante va como texto dentro de <comprobante>
                $envuelto = $xml->getName() === 'autorizacion' ? (string) ($xml->comprobante ?? '') : (string) ($xml->autorizacion->comprobante ?? '');
                $xml = trim($envuelto) !== '' ? simplexml_load_string($envuelto) : false;
            }
            if ($xml === false || $xml->getName() !== 'comprobanteRetencion') {
                return [];
            }
            $lineas = [];
            foreach ($xml->xpath('//impuesto[valorRetenido] | //retencion[valorRetenido]') ?: [] as $nodo) {
                $lineas[] = [trim((string) $nodo->codigo), (float) $nodo->valorRetenido];
            }

            return $lineas;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }
}
