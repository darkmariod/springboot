<?php
namespace App\Services;
use InvalidArgumentException;
use SimpleXMLElement;

class ParseSriPurchaseXml {
    public function parse(string $contenido): array {
        $factura = $this->extraerFactura($contenido);
        $it = $factura->infoTributaria;
        $inf = $factura->infoFactura;
        if (!isset($it, $inf)) throw new InvalidArgumentException('El XML no es una factura del SRI.');

        $items = [];
        foreach ($factura->detalles->detalle ?? [] as $d) {
            $base=0; $iva=0; $tarifa=0;
            foreach ($d->impuestos->impuesto ?? [] as $imp) {
                if ((string)$imp->codigo === '2') { $base += (float)$imp->baseImponible; $iva += (float)$imp->valor; $tarifa=(float)$imp->tarifa; }
            }
            $items[] = ['codigo_principal'=>trim((string)($d->codigoPrincipal ?? '')), 'descripcion'=>trim((string)$d->descripcion),
                'cantidad'=>(float)$d->cantidad, 'precio_unitario'=>(float)$d->precioUnitario,
                'base_imponible'=>round($base,2), 'tarifa'=>$tarifa, 'valor_iva'=>round($iva,2)];
        }
        $totalImp=0;
        foreach ($inf->totalConImpuestos->totalImpuesto ?? [] as $imp)
            if ((string)$imp->codigo === '2') $totalImp += (float)$imp->valor;

        return [
            'proveedor'=>['tipo_identificacion'=>'04','identificacion'=>trim((string)$it->ruc),
                'razon_social'=>trim((string)$it->razonSocial),
                'direccion'=>isset($it->dirMatriz)?trim((string)$it->dirMatriz):null],
            'comprobante'=>['numero'=>sprintf('%s-%s-%s',(string)$it->estab,(string)$it->ptoEmi,(string)$it->secuencial),
                'clave_acceso'=>trim((string)$it->claveAcceso) ?: null, 'fecha_emision'=>$this->fecha((string)$inf->fechaEmision)],
            'items'=>$items,
            'totales'=>['total_sin_impuestos'=>(float)$inf->totalSinImpuestos,'total_impuesto'=>round($totalImp,2),'importe_total'=>(float)$inf->importeTotal],
            'xml'=>$contenido,
        ];
    }
    private function extraerFactura(string $c): SimpleXMLElement {
        $xml = @simplexml_load_string($c);
        if ($xml === false) throw new InvalidArgumentException('El archivo no es un XML válido.');
        if ($xml->getName() === 'factura') return $xml;
        // Sobre de autorización con <comprobante> en CDATA
        $nodo = $xml->getName()==='autorizacion' ? $xml : ($xml->autorizacion ?? null);
        if ($nodo && isset($nodo->comprobante)) {
            $int = @simplexml_load_string((string)$nodo->comprobante);
            if ($int !== false && $int->getName()==='factura') return $int;
        }
        throw new InvalidArgumentException('No se encontró una factura dentro del XML.');
    }
    private function fecha(string $f): string {
        $f = trim($f);
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})#',$f,$m) ? "{$m[3]}-{$m[2]}-{$m[1]}" : date('Y-m-d');
    }
}
