<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\PaymentSplit;
use App\Support\Cuentas;
use App\Support\MovimientosBanco;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Splits a payment across several methods (like the KVS "Forma Pago" grid)
 * and posts one journal entry with a line per method.
 *
 * El sentido es explícito, nunca se adivina:
 *  - COBRO (venta):  Debe Caja/Banco/etc. por cada forma, Haber la cuenta que se salda (CxC).
 *  - PAGO (compra):  Debe la cuenta que se salda (CxP), Haber Caja/Banco/etc. por cada forma.
 *
 * Cada forma que pasa por un banco (con bank_id) deja además su movimiento en la conciliación bancaria
 * (App\Support\MovimientosBanco), sin otro asiento.
 */
class RegistrarPagos
{
    public const COBRO = 'cobro';
    public const PAGO = 'pago';

    /**
     * @param  array  $pagos  [['tipo'=>'efectivo','valor'=>100,'bank_id'=>null,'documento'=>null], ...]
     * @param  string  $contra  código de la cuenta que se salda: la CxC o CxP del documento (Cuentas::cxcDe / cxpDe)
     * @param  string  $sentido  self::COBRO o self::PAGO
     * @param  string|null  $ref  referencia de las líneas; por defecto el número del documento
     */
    public function handle(Model $documento, array $pagos, string $contra, string $concepto, string $sentido, ?string $ref = null): float
    {
        if (! in_array($sentido, [self::COBRO, self::PAGO], true)) {
            throw new InvalidArgumentException("Sentido de pago desconocido: $sentido (usa 'cobro' o 'pago').");
        }
        $esCobro = $sentido === self::COBRO;

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
            if ($catalogo[$tipo]['es_cruce'] ?? false) {
                // Un cruce no es dinero: lo registra CruceSaldos contra un documento del mismo contacto
                abort(422, 'El cruce de saldos se registra contra un documento, no como una forma de pago normal.');
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

            $conceptoCuenta = $catalogo[$tipo]['cuenta'] ?? null;
            $cuenta = $conceptoCuenta ? Cuentas::get($conceptoCuenta) : null;
            if (! $cuenta && ! empty($p['cuenta_codigo'])) {
                $cuenta = ['codigo' => $p['cuenta_codigo'], 'nombre' => 'Cuenta contable', 'tipo' => 'activo'];
            }
            if (! $cuenta) abort(422, "La forma de pago $tipo necesita una cuenta contable.");

            $lineas[] = $cuenta + [
                'debe' => $esCobro ? $valor : 0,
                'haber' => $esCobro ? 0 : $valor,
                'ref' => $ref ?? $documento->numero ?? null,
            ];
            $total += $valor;

            // Por banco: el movimiento queda listo para conciliar (egreso en pagos, ingreso en cobros)
            if ($conceptoCuenta === 'bancos') {
                MovimientosBanco::registrar($documento->company_id, $p['bank_id'] ?? null,
                    $esCobro ? MovimientosBanco::COBRO : MovimientosBanco::PAGO, $valor, $concepto,
                    $p['documento'] ?? null, $documento, $p['fecha'] ?? null);
            }
        }

        if (! $lineas) return 0;

        // La contrapartida: lo que se salda
        $total = round($total, 2);
        $cuentaContra = Cuentas::porCodigo($contra)
            ?? ['codigo' => $contra, 'nombre' => 'Contrapartida', 'tipo' => $esCobro ? 'activo' : 'pasivo'];
        $lineaContra = $cuentaContra + [
            'debe' => $esCobro ? 0 : $total,
            'haber' => $esCobro ? $total : 0,
            'ref' => $ref ?? $documento->numero ?? null,
        ];
        $lineas = $esCobro ? array_merge($lineas, [$lineaContra]) : array_merge([$lineaContra], $lineas);

        SimpleEntry::make($documento->company_id, $concepto, $lineas, $documento);

        return $total;
    }
    /**
     * Un solo pago que salda VARIAS compras a la vez (de uno o de varios proveedores) con una sola tanda
     * de formas de pago, cuya suma debe ser igual al total. Un solo asiento:
     *   Debe la CxP de cada compra (una línea por cuenta: normal o de partes relacionadas) / Haber cada forma.
     * Cada compra recibe su reparto de las formas (de la primera a la última) para sus PurchasePayment y PaymentSplit.
     * No admite cruce de saldos: un cruce necesita su documento contrario y se hace compra por compra.
     *
     * @param  array<int,array{compra:Model,monto:float|int|string}>  $compras  cada compra con lo que se le paga
     * @param  array  $formas  [['tipo'=>'cheque_banco','valor'=>100,'bank_id'=>1,'documento'=>'004512'], ...]
     * @return array{total:float,asiento:JournalEntry,reparto:array<int,array<int,array>>} reparto: por clave de $compras, sus tramos [tipo, monto, bank_id, documento, fecha]
     */
    public function pagarVarios(int $companyId, array $compras, array $formas, string $concepto): array
    {
        $catalogo = config('formas_pago');
        $totalCentavos = 0;
        foreach ($compras as $c) {
            $totalCentavos += self::centavos($c['monto']);
        }

        // Formas: solo con valor, del catálogo, sin cruce y con su cuenta contable
        $pool = [];
        $sumaCentavos = 0;
        foreach ($formas as $f) {
            $valor = self::centavos($f['valor'] ?? 0);
            if ($valor <= 0) continue;
            $tipo = (string) ($f['tipo'] ?? '');
            if (! isset($catalogo[$tipo])) {
                self::falla("Forma de pago desconocida: $tipo");
            }
            if ($catalogo[$tipo]['es_cruce'] ?? false) {
                self::falla('El cruce de saldos no se puede usar en un pago a varios proveedores: paga cada compra por separado con la forma Cruce de saldos e indica el documento contra el que se cruza.');
            }
            $conceptoCuenta = $catalogo[$tipo]['cuenta'] ?? null;
            $cuenta = $conceptoCuenta ? Cuentas::get($conceptoCuenta) : null;
            if (! $cuenta && ! empty($f['cuenta_codigo'])) {
                $cuenta = ['codigo' => $f['cuenta_codigo'], 'nombre' => 'Cuenta contable', 'tipo' => 'activo'];
            }
            if (! $cuenta) {
                self::falla("La forma de pago $tipo necesita una cuenta contable.");
            }
            $pool[] = ['tipo' => $tipo, 'centavos' => $valor, 'restante' => $valor, 'cuenta' => $cuenta, 'concepto_cuenta' => $conceptoCuenta,
                'bank_id' => $f['bank_id'] ?? null, 'documento' => $f['documento'] ?? null, 'fecha' => $f['fecha'] ?? null];
            $sumaCentavos += $valor;
        }
        if (! $pool) {
            self::falla('Indica al menos una forma de pago.');
        }
        if ($sumaCentavos !== $totalCentavos) {
            self::falla(sprintf('La suma de las formas de pago ($%s) debe ser igual al total a pagar ($%s).',
                number_format($sumaCentavos / 100, 2, '.', ''), number_format($totalCentavos / 100, 2, '.', '')));
        }

        // Reparto: a cada compra se le asignan las formas en orden hasta cubrir su monto
        $reparto = [];
        $porCuentaPorPagar = [];
        $hoy = now()->toDateString();
        foreach ($compras as $clave => $c) {
            $compra = $c['compra'];
            $falta = self::centavos($c['monto']);
            $conceptoCxp = Cuentas::cxpDe($compra);
            $porCuentaPorPagar[$conceptoCxp] = ($porCuentaPorPagar[$conceptoCxp] ?? 0) + $falta;
            $reparto[$clave] = [];
            foreach ($pool as &$forma) {
                if ($falta <= 0) break;
                $toma = min($falta, $forma['restante']);
                if ($toma <= 0) continue;
                $forma['restante'] -= $toma;
                $falta -= $toma;
                $tramo = ['tipo' => $forma['tipo'], 'monto' => $toma / 100, 'bank_id' => $forma['bank_id'],
                    'documento' => $forma['documento'], 'fecha' => $forma['fecha'] ?? $hoy];
                $reparto[$clave][] = $tramo;
                PaymentSplit::create([
                    'company_id' => $companyId, 'pagable_type' => $compra->getMorphClass(), 'pagable_id' => $compra->getKey(),
                    'tipo' => $tramo['tipo'], 'fecha' => $tramo['fecha'], 'valor' => $tramo['monto'],
                    'bank_id' => $tramo['bank_id'], 'documento' => $tramo['documento'], 'detalle' => 'Pago múltiple a proveedores',
                ]);
            }
            unset($forma);
        }

        // Un asiento: Debe cada CxP / Haber cada forma de pago
        $lineas = [];
        foreach ($porCuentaPorPagar as $conceptoCxp => $centavos) {
            $lineas[] = Cuentas::linea($conceptoCxp, $centavos / 100, 0, 'PAGO-MULT');
        }
        foreach ($pool as $forma) {
            $lineas[] = $forma['cuenta'] + ['debe' => 0, 'haber' => $forma['centavos'] / 100, 'ref' => $forma['documento'] ?: 'PAGO-MULT'];
        }
        $asiento = SimpleEntry::make($companyId, $concepto, $lineas);

        // Cada forma por banco, un solo movimiento por el valor completo (así aparece en el estado de cuenta)
        foreach ($pool as $forma) {
            if ($forma['concepto_cuenta'] === 'bancos') {
                MovimientosBanco::registrar($companyId, $forma['bank_id'], MovimientosBanco::PAGO, $forma['centavos'] / 100,
                    $concepto, $forma['documento'], $asiento, $forma['fecha']);
            }
        }

        return ['total' => $totalCentavos / 100, 'asiento' => $asiento, 'reparto' => $reparto];
    }

    private static function centavos(mixed $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    private static function falla(string $mensaje): never
    {
        throw ValidationException::withMessages(['formas' => [$mensaje]]);
    }
}
