<?php
namespace App\Services;
use Carbon\Carbon;
use InvalidArgumentException;
use SimpleXMLElement;

/**
 * Lee el XML de una factura EMITIDA por la empresa (el que baja del portal del SRI: el sobre de
 * autorización con la factura dentro de <comprobante>). Solo extrae lo que el XML dice; no calcula ni
 * inventa nada. Si es una factura suelta, sin sobre, `autorizacion` queda en null.
 */
class ParseSriIssuedXml {
    public function parse(string $contenido): array {
        [$factura, $autorizacion, $comprobante] = $this->extraer($contenido);
        $it = $factura->infoTributaria;
        $inf = $factura->infoFactura;
        if (! isset($it->ruc, $inf->fechaEmision)) throw new InvalidArgumentException('El XML no es una factura del SRI.');

        $items = [];
        foreach ($factura->detalles->detalle ?? [] as $d) {
            $base = 0; $iva = 0; $tarifa = 0; $codPorcentaje = null;
            foreach ($d->impuestos->impuesto ?? [] as $imp) {
                if ((string)$imp->codigo === '2') {
                    $base += (float)$imp->baseImponible; $iva += (float)$imp->valor;
                    $tarifa = (float)$imp->tarifa; $codPorcentaje = (string)$imp->codigoPorcentaje;
                }
            }
            $codigo = trim((string)($d->codigoPrincipal ?? ''));
            if ($codigo === '') $codigo = trim((string)($d->codigoInterno ?? ''));
            $items[] = [
                'codigo_principal'=>$codigo, 'descripcion'=>trim((string)$d->descripcion),
                'cantidad'=>(float)$d->cantidad, 'precio_unitario'=>(float)$d->precioUnitario,
                'descuento'=>(float)($d->descuento ?? 0),
                'tarifa'=>$tarifa, 'codigo_impuesto'=>'2', 'codigo_porcentaje'=>$codPorcentaje,
                'base_imponible'=>round($base,2), 'valor_iva'=>round($iva,2),
            ];
        }
        $totalImpuestos = 0;
        foreach ($inf->totalConImpuestos->totalImpuesto ?? [] as $imp) $totalImpuestos += (float)$imp->valor;

        return [
            'emisor'=>['ruc'=>trim((string)$it->ruc), 'razon_social'=>trim((string)$it->razonSocial),
                'estab'=>trim((string)$it->estab), 'pto_emi'=>trim((string)$it->ptoEmi), 'secuencial'=>trim((string)$it->secuencial),
                'ambiente'=>(int)(string)$it->ambiente],
            'comprador'=>['tipo_identificacion'=>trim((string)$inf->tipoIdentificacionComprador),
                'identificacion'=>trim((string)$inf->identificacionComprador),
                'razon_social'=>trim((string)$inf->razonSocialComprador),
                'direccion'=>isset($inf->direccionComprador) ? trim((string)$inf->direccionComprador) : null],
            'comprobante'=>['numero'=>sprintf('%s-%s-%s',(string)$it->estab,(string)$it->ptoEmi,(string)$it->secuencial),
                'clave_acceso'=>trim((string)$it->claveAcceso) ?: null, 'fecha_emision'=>$this->fecha((string)$inf->fechaEmision)],
            'autorizacion'=>$autorizacion,
            'items'=>$items,
            'totales'=>['total_sin_impuestos'=>(float)$inf->totalSinImpuestos, 'total_descuento'=>(float)($inf->totalDescuento ?? 0),
                'total_impuesto'=>round($totalImpuestos,2), 'importe_total'=>(float)$inf->importeTotal],
            'xml'=>$comprobante,
        ];
    }

    /** @return array{0:SimpleXMLElement,1:?array,2:string} la factura, los datos de la autorización (null si viene suelta) y el XML de la factura */
    private function extraer(string $c): array {
        $xml = @simplexml_load_string($c);
        if ($xml === false) throw new InvalidArgumentException('El archivo no es un XML válido.');
        if ($xml->getName() === 'factura') return [$xml, null, $c];
        // Sobre de autorización con <comprobante> en CDATA
        $nodo = $xml->getName()==='autorizacion' ? $xml : ($xml->autorizacion ?? null);
        if ($nodo && isset($nodo->comprobante)) {
            $interno = (string)$nodo->comprobante;
            $factura = @simplexml_load_string($interno);
            if ($factura !== false && $factura->getName()==='factura')
                return [$factura, [
                    'estado'=>strtoupper(trim((string)$nodo->estado)),
                    'numero'=>trim((string)$nodo->numeroAutorizacion) ?: null,
                    'fecha'=>$this->fechaHora((string)$nodo->fechaAutorizacion),
                ], $interno];
        }
        throw new InvalidArgumentException('No se encontró una factura dentro del XML.');
    }

    private function fecha(string $f): string {
        $f = trim($f);
        if (! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#',$f,$m) || ! checkdate((int)$m[2],(int)$m[1],(int)$m[3]))
            throw new InvalidArgumentException('El XML no trae una fecha de emisión válida.');
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }

    private function fechaHora(string $f): ?Carbon {
        try { return trim($f) === '' ? null : Carbon::parse($f); } catch (\Throwable) { return null; }
    }
}
