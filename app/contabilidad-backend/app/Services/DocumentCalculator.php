<?php
namespace App\Services;
class DocumentCalculator {
    public function fromItems(array $items): array {
        $detalle=[]; $totalSin=0; $totalDesc=0; $impPorTarifa=[];
        foreach ($items as $item) {
            $desc = $item['descuento'] ?? 0;
            $subtotal = round($item['cantidad']*$item['precio_unitario']-$desc, 2);
            $codImp = $item['codigo_impuesto'] ?? '2';
            $codPct = $item['codigo_porcentaje'] ?? '4';
            $tarifa = $item['tarifa'] ?? 15;
            $valorImp = round($subtotal*$tarifa/100, 2);
            $totalSin += $subtotal; $totalDesc += $desc;
            $k = $codImp.'-'.$codPct;
            $impPorTarifa[$k] ??= ['codigo'=>$codImp,'codigoPorcentaje'=>$codPct,'baseImponible'=>0,'valor'=>0];
            $impPorTarifa[$k]['baseImponible'] += $subtotal;
            $impPorTarifa[$k]['valor'] += $valorImp;
            $detalle[] = [
                'codigoPrincipal'=>$item['codigo_principal'], 'codigoAuxiliar'=>$item['codigo_principal'],
                'descripcion'=>$item['descripcion'],
                'cantidad'=>number_format($item['cantidad'],2,'.',''),
                'precioUnitario'=>number_format($item['precio_unitario'],2,'.',''),
                'descuento'=>number_format($desc,2,'.',''),
                'precioTotalSinImpuesto'=>number_format($subtotal,2,'.',''),
                'impuesto'=>['codigo'=>$codImp,'codigoPorcentaje'=>$codPct,'tarifa'=>(string)$tarifa,
                    'baseImponible'=>number_format($subtotal,2,'.',''),'valor'=>number_format($valorImp,2,'.','')],
            ];
        }
        $totalImp = round(array_sum(array_column($impPorTarifa,'valor')), 2);
        return ['detalle'=>$detalle,'total_sin_impuestos'=>round($totalSin,2),'total_descuento'=>round($totalDesc,2),
            'total_impuesto'=>$totalImp,'importe_total'=>round($totalSin+$totalImp,2),'impuestos'=>array_values($impPorTarifa)];
    }
}
