<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\Withholding;
use App\Support\DesgloseRetencion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Módulo Impuestos — Formulario 104 y ATS.
 * La contadora usa esto cada mes para declarar IVA.
 */
class TaxController extends Controller
{
    /**
     * Formulario 104: Resumen de IVA del período.
     *
     * IVA a pagar = IVA cobrado en facturas
     *             − IVA de las notas de crédito emitidas en el período (bajan las ventas y el IVA cobrado)
     *             − IVA pagado en compras (crédito tributario)
     *             − retenciones de IVA que nos hicieron
     *             + retenciones de IVA que nosotros hicimos como agente de retención (se pagan con esta declaración).
     * Las retenciones de RENTA que nos hicieron no tocan el IVA (son crédito del impuesto a la renta: se muestran aparte) y las de
     * renta que nosotros hicimos van al formulario 103. La contadora debe validar esta fórmula antes de declarar.
     *
     * Las llaves de siempre (`ventas.*`, `compras.*`, `retenciones.recibidas|emitidas`, `saldo_iva`, `declaracion`, `resumen`) se
     * conservan: `ventas.*` ahora es lo NETO de notas de crédito (lo que se declara) y `ventas.facturas` / `ventas.notas_credito`
     * traen el desglose. `calculo_iva` trae el saldo línea por línea.
     */
    public function formulario104(Request $r)
    {
        $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);

        $companyId = $r->company_id;
        $desde = Carbon::parse($r->desde)->startOfDay();
        $hasta = Carbon::parse($r->hasta)->endOfDay();

        // === VENTAS ===
        // Solo facturas: una nota de débito (SRI o interna) no es una venta; una factura que el SRI rechazó tampoco
        $ventas = Invoice::soloFacturas()->sinRechazoSri()->where('estado', '!=', 'anulado')->where('company_id', $companyId)
            ->whereBetween('fecha_emision', [$desde, $hasta])
            ->get();

        $ventas0 = $ventas->filter(fn($v) => (float)$v->total_impuesto == 0)->sum('importe_total');
        $ventas12 = $ventas->filter(fn($v) => (float)$v->total_impuesto > 0)->sum('total_sin_impuestos');
        $ivaFacturas = $ventas->sum('total_impuesto');
        $totalFacturas = $ventas->sum('importe_total');

        // === NOTAS DE CRÉDITO EMITIDAS EN EL PERÍODO ===
        // Cuentan las que no se anularon y que el SRI no rechazó (igual que las facturas); la interna de una empresa sin SRI cuenta
        // como la factura sin comprobante. Bajan las ventas de su tarifa y el IVA cobrado.
        $rechazados = SriDocument::ESTADOS_RECHAZADO;
        $notas = CreditNote::with('sriDocument:id,documentable_id,documentable_type,estado')
            ->where('company_id', $companyId)->where('tipo', '!=', 'anulado')
            ->whereDate('fecha', '>=', $desde->toDateString())->whereDate('fecha', '<=', $hasta->toDateString())
            ->whereDoesntHave('sriDocument', fn ($sd) => $sd->whereRaw('UPPER(estado) in ('.implode(',', array_fill(0, count($rechazados), '?')).')', $rechazados))
            ->get();

        $notas0 = $notas->filter(fn($n) => (float)$n->total_impuesto == 0)->sum('importe_total');
        $notas12 = $notas->filter(fn($n) => (float)$n->total_impuesto > 0)->sum('total_sin_impuestos');
        $ivaNotas = $notas->sum('total_impuesto');
        $totalNotas = $notas->sum('importe_total');
        // Notas del SRI guardadas que todavía no se emiten: ya restan aquí, pero el SRI no las conoce
        $notasSinEmitir = $notas->filter(fn($n) => $n->tipo === 'sri' && ! $n->sriDocument)->count();

        $ivaCobradoNeto = $ivaFacturas - $ivaNotas;

        // === COMPRAS ===
        $compras = Purchase::where('company_id', $companyId)
            ->whereBetween('fecha_emision', [$desde, $hasta])
            ->get();

        $compras0 = $compras->filter(fn($c) => (float)$c->total_impuesto == 0)->sum('importe_total');
        $compras12 = $compras->filter(fn($c) => (float)$c->total_impuesto > 0)->sum('total_sin_impuestos');
        $ivaPagado = $compras->sum('total_impuesto');

        // === RETENCIONES ===
        // Cada retención se separa en IVA y renta (ver DesgloseRetencion): solo las de IVA entran al saldo del IVA.
        // Fechas como día (no como hora): una retención del primer o del último día del período también cuenta.
        $retenciones = Withholding::where('company_id', $companyId)
            ->whereDate('fecha', '>=', $desde->toDateString())->whereDate('fecha', '<=', $hasta->toDateString())
            ->get();
        $suma = ['recibida' => ['iva' => 0.0, 'renta' => 0.0, 'otras' => 0.0], 'emitida' => ['iva' => 0.0, 'renta' => 0.0, 'otras' => 0.0]];
        foreach ($retenciones as $w) {
            $lado = $w->tipo === 'emitida' ? 'emitida' : 'recibida';
            foreach (DesgloseRetencion::de($w) as $clase => $valor) {
                $suma[$lado][$clase] += $valor;
            }
        }
        $recibidasIva = round($suma['recibida']['iva'], 2);
        $recibidasRenta = round($suma['recibida']['renta'], 2);
        $recibidasOtras = round($suma['recibida']['otras'], 2);
        $emitidasIva = round($suma['emitida']['iva'], 2);
        $emitidasRenta = round($suma['emitida']['renta'], 2);

        // === SALDO IVA ===
        $saldoIVA = round($ivaCobradoNeto - $ivaPagado - $recibidasIva + $emitidasIva, 2);

        return [
            'periodo' => ['desde' => $r->desde, 'hasta' => $r->hasta],
            'ventas' => [
                'ventas_0' => round($ventas0 - $notas0, 2),
                'ventas_12' => round($ventas12 - $notas12, 2),
                'base_imponible_12' => round($ventas12 - $notas12, 2),
                'iva_cobrado' => round($ivaCobradoNeto, 2),
                'total_ventas' => round($totalFacturas - $totalNotas, 2),
                'facturas' => [
                    'cantidad' => $ventas->count(),
                    'total_ventas' => round($totalFacturas, 2),
                    'ventas_0' => round($ventas0, 2),
                    'ventas_12' => round($ventas12, 2),
                    'iva_cobrado' => round($ivaFacturas, 2),
                ],
                'notas_credito' => [
                    'cantidad' => $notas->count(),
                    'total' => round($totalNotas, 2),
                    'ventas_0' => round($notas0, 2),
                    'ventas_12' => round($notas12, 2),
                    'iva' => round($ivaNotas, 2),
                    'sin_emitir' => $notasSinEmitir,
                ],
            ],
            'compras' => [
                'compras_0' => round($compras0, 2),
                'compras_12' => round($compras12, 2),
                'base_imponible_12' => round($compras12, 2),
                'iva_pagado' => round($ivaPagado, 2),
                'total_compras' => round($compras->sum('importe_total'), 2),
            ],
            'retenciones' => [
                'recibidas' => round(array_sum($suma['recibida']), 2),
                'emitidas' => round(array_sum($suma['emitida']), 2),
                'recibidas_iva' => $recibidasIva,
                'recibidas_renta' => $recibidasRenta,
                'recibidas_otras' => $recibidasOtras,
                'emitidas_iva' => $emitidasIva,
                'emitidas_renta' => $emitidasRenta,
            ],
            // El saldo línea por línea: lo que la pantalla muestra y lo que suma "a pagar"
            'calculo_iva' => [
                'iva_cobrado_facturas' => round($ivaFacturas, 2),
                'menos_iva_notas_credito' => round($ivaNotas, 2),
                'iva_cobrado_neto' => round($ivaCobradoNeto, 2),
                'menos_credito_tributario_compras' => round($ivaPagado, 2),
                'menos_retenciones_iva_recibidas' => $recibidasIva,
                'mas_retenciones_iva_emitidas' => $emitidasIva,
                'a_pagar' => $saldoIVA,
                // Informativo: no entra al saldo, es crédito para el impuesto a la renta
                'retenciones_renta_recibidas_informativo' => $recibidasRenta,
            ],
            'saldo_iva' => $saldoIVA,
            'declaracion' => $saldoIVA > 0 ? 'PAGAR' : 'DEVOLUCIÓN',
            'resumen' => [
                'total_facturas' => $ventas->count(),
                'total_compras' => $compras->count(),
                'total_notas_credito' => $notas->count(),
            ],
        ];
    }

    /**
     * ATS (Anexo de Transacciones SRI): Resumen anual para la declaración.
     */
    public function ats(Request $r)
    {
        $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'anio' => ['required', 'integer', 'min:2020', 'max:2030'],
        ]);

        $companyId = $r->company_id;
        $anio = $r->anio;

        $ventasMensuales = [];
        $comprasMensuales = [];

        for ($mes = 1; $mes <= 12; $mes++) {
            $desde = Carbon::create($anio, $mes, 1)->startOfMonth();
            $hasta = Carbon::create($anio, $mes, 1)->endOfMonth();

            $ventasMes = Invoice::soloFacturas()->sinRechazoSri()->where('estado', '!=', 'anulado')->where('company_id', $companyId)
                ->whereBetween('fecha_emision', [$desde, $hasta])->get();

            $comprasMes = Purchase::where('company_id', $companyId)
                ->whereBetween('fecha_emision', [$desde, $hasta])->get();

            $ventasMensuales[$mes] = [
                'mes' => $mes,
                'total_ventas' => round($ventasMes->sum('importe_total'), 2),
                'ventas_12' => round($ventasMes->filter(fn($v) => (float)$v->total_impuesto > 0)->sum('total_sin_impuestos'), 2),
                'ventas_0' => round($ventasMes->filter(fn($v) => (float)$v->total_impuesto == 0)->sum('importe_total'), 2),
                'iva_cobrado' => round($ventasMes->sum('total_impuesto'), 2),
                'num_facturas' => $ventasMes->count(),
            ];

            $comprasMensuales[$mes] = [
                'mes' => $mes,
                'total_compras' => round($comprasMes->sum('importe_total'), 2),
                'compras_12' => round($comprasMes->filter(fn($c) => (float)$c->total_impuesto > 0)->sum('total_sin_impuestos'), 2),
                'compras_0' => round($comprasMes->filter(fn($c) => (float)$c->total_impuesto == 0)->sum('importe_total'), 2),
                'iva_pagado' => round($comprasMes->sum('total_impuesto'), 2),
                'num_compras' => $comprasMes->count(),
            ];
        }

        $totalVentasAnual = array_sum(array_column($ventasMensuales, 'total_ventas'));
        $totalIvaCobrado = array_sum(array_column($ventasMensuales, 'iva_cobrado'));
        $totalComprasAnual = array_sum(array_column($comprasMensuales, 'total_compras'));
        $totalIvaPagado = array_sum(array_column($comprasMensuales, 'iva_pagado'));

        return [
            'anio' => $anio,
            'ventas_mensuales' => $ventasMensuales,
            'compras_mensuales' => $comprasMensuales,
            'resumen_anual' => [
                'total_ventas' => round($totalVentasAnual, 2),
                'total_iva_cobrado' => round($totalIvaCobrado, 2),
                'total_compras' => round($totalComprasAnual, 2),
                'total_iva_pagado' => round($totalIvaPagado, 2),
                'saldo_iva_anual' => round($totalIvaCobrado - $totalIvaPagado, 2),
            ],
        ];
    }
}
