<?php
namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Arma el detalle y los totales de un comprobante a partir de sus líneas.
 *
 * El código de porcentaje del IVA (tabla del SRI) sale de la TARIFA de la línea: 0% → '0', 15% → '4', etc. Antes todo
 * llevaba '4' aunque la tarifa fuera 0 y el XML decía "15%" con valor cero. 'No objeto de IVA' ('6') y 'Exento' ('7')
 * solo van cuando la línea lo pide con `codigo_porcentaje`; no cobran IVA. Si la línea trae solo el código, la tarifa sale
 * del código. Si trae los dos y se contradicen, gana la tarifa: el IVA se calculó con ella y el comprobante no puede decir otra cosa.
 */
class DocumentCalculator {
    /** Tarifa de IVA (%) → código de porcentaje del SRI. Son las tarifas que el sistema sabe emitir. */
    public const CODIGO_POR_TARIFA = ['0' => '0', '5' => '5', '8' => '8', '12' => '2', '13' => '10', '14' => '3', '15' => '4'];
    /** Código de porcentaje → tarifa. '6' (no objeto) y '7' (exento) no cobran IVA. */
    public const TARIFA_POR_CODIGO = ['0' => 0, '2' => 12, '3' => 14, '4' => 15, '5' => 5, '6' => 0, '7' => 0, '8' => 8, '10' => 13];
    /** Códigos que no cobran IVA aunque la línea traiga una tarifa. */
    public const CODIGOS_SIN_IVA = ['6', '7'];

    /** Código de porcentaje del SRI para una tarifa, o null si el SRI no tiene esa tarifa. */
    public static function codigoDeTarifa(float $tarifa): ?string {
        return self::CODIGO_POR_TARIFA[rtrim(rtrim(number_format($tarifa, 2, '.', ''), '0'), '.')] ?? null;
    }

    public function fromItems(array $items): array {
        $detalle=[]; $totalSin=0; $totalDesc=0; $impPorTarifa=[];
        foreach ($items as $n => $item) {
            $desc = round((float) ($item['descuento'] ?? 0), 2);
            $bruto = round($item['cantidad']*$item['precio_unitario'], 2);
            $subtotal = round($bruto-$desc, 2);
            if ($desc < 0 || $subtotal < 0) {
                throw ValidationException::withMessages(['items.'.$n.'.descuento' => ['El descuento de la línea '.($n + 1).' ($'.number_format($desc, 2).') no puede ser negativo ni mayor que su valor ($'.number_format($bruto, 2).').']]);
            }
            $codImp = $item['codigo_impuesto'] ?? '2';
            [$codPct, $tarifa] = $this->ivaDeLaLinea($item, $n);
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
        foreach ($impPorTarifa as &$imp) { $imp['baseImponible'] = round($imp['baseImponible'], 2); $imp['valor'] = round($imp['valor'], 2); }
        unset($imp);
        $totalImp = round(array_sum(array_column($impPorTarifa,'valor')), 2);
        return ['detalle'=>$detalle,'total_sin_impuestos'=>round($totalSin,2),'total_descuento'=>round($totalDesc,2),
            'total_impuesto'=>$totalImp,'importe_total'=>round($totalSin+$totalImp,2),'impuestos'=>array_values($impPorTarifa)];
    }

    /** @return array{0: string, 1: int|float} [código de porcentaje, tarifa] de la línea */
    private function ivaDeLaLinea(array $item, int|string $n): array {
        $codigo = isset($item['codigo_porcentaje']) && $item['codigo_porcentaje'] !== '' ? (string) $item['codigo_porcentaje'] : null;
        $tarifa = isset($item['tarifa']) && $item['tarifa'] !== '' ? (float) $item['tarifa'] : null;

        if ($codigo !== null && in_array($codigo, self::CODIGOS_SIN_IVA, true)) {
            return [$codigo, 0];
        }
        if ($tarifa === null) {
            // Solo el código: la tarifa sale de él. Sin nada, 15% (así lo calcula el punto de venta).
            $tarifa = $codigo !== null && isset(self::TARIFA_POR_CODIGO[$codigo]) ? (float) self::TARIFA_POR_CODIGO[$codigo] : 15.0;
        }
        $derivado = self::codigoDeTarifa($tarifa);
        if ($derivado === null) {
            throw ValidationException::withMessages(['items.'.$n.'.tarifa' => ['La línea '.($n + 1).' tiene IVA de '.rtrim(rtrim(number_format($tarifa, 2, '.', ''), '0'), '.')
                .'%, una tarifa que el SRI no tiene. Usa 0%, 5%, 8%, 12%, 13%, 14% o 15%.']]);
        }

        return [$derivado, $tarifa == (int) $tarifa ? (int) $tarifa : $tarifa];
    }
}
