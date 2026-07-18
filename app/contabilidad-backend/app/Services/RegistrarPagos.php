<?php

namespace App\Services;

use App\Models\PaymentSplit;
use Illuminate\Database\Eloquent\Model;

/**
 * Splits a payment across several methods (like the KVS "Forma Pago" grid)
 * and posts one journal entry with a line per method.
 */
class RegistrarPagos
{
    /**
     * @param  array  $pagos  [['tipo'=>'efectivo','valor'=>100,'bank_id'=>null,'documento'=>null], ...]
     * @param  string  $contra  código de la cuenta que se salda (1.1.03 CxC o 2.1.01 CxP)
     */
    public function handle(Model $documento, array $pagos, string $contra, string $concepto): float
    {
        $catalogo = config('formas_pago');
        $lineas = [];
        $total = 0;

        foreach ($pagos as $p) {
            $tipo = $p['tipo'];
            $valor = round((float) $p['valor'], 2);
            if ($valor <= 0) continue;
            if (! isset($catalogo[$tipo])) {
                abort(422, "Forma de pago desconocida: $tipo");
            }

            PaymentSplit::create([
                'company_id' => $documento->company_id,
                'pagable_type' => $documento->getMorphClass(),
                'pagable_id' => $documento->getKey(),
                'tipo' => $tipo,
                'fecha' => $p['fecha'] ?? now()->toDateString(),
                'valor' => $valor,
                'bank_id' => $p['bank_id'] ?? null,
                'cash_register_id' => $p['cash_register_id'] ?? null,
                'documento' => $p['documento'] ?? null,
                'detalle' => $p['detalle'] ?? null,
            ]);

            $cuenta = $catalogo[$tipo]['cuenta'] ?? null;
            if (! $cuenta && ! empty($p['cuenta_codigo'])) {
                $cuenta = ['codigo' => $p['cuenta_codigo'], 'nombre' => 'Cuenta contable', 'tipo' => 'activo'];
            }
            if (! $cuenta) abort(422, "La forma de pago $tipo necesita una cuenta contable.");

            $lineas[] = $cuenta + ['debe' => $valor, 'haber' => 0, 'ref' => $documento->numero ?? null];
            $total += $valor;
        }

        if (! $lineas) return 0;

        // La contrapartida: lo que se salda
        $lineas[] = ['codigo' => $contra, 'nombre' => 'Contrapartida', 'tipo' => 'activo',
            'debe' => 0, 'haber' => round($total, 2), 'ref' => $documento->numero ?? null];

        SimpleEntry::make($documento->company_id, $concepto, $lineas, $documento);

        return round($total, 2);
    }
}
