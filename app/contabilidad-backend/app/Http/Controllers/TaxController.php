<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\Withholding;
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
     * Devuelve ventas, compras, IVA cobrado, IVA pagado, saldo.
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
        $ventas = Invoice::where('company_id', $companyId)
            ->whereBetween('fecha_emision', [$desde, $hasta])
            ->get();

        $ventas0 = $ventas->filter(fn($v) => (float)$v->total_impuesto === 0)->sum('importe_total');
        $ventas12 = $ventas->filter(fn($v) => (float)$v->total_impuesto > 0)->sum('total_sin_impuestos');
        $ivaCobrado = $ventas->sum('total_impuesto');

        // === COMPRAS ===
        $compras = Purchase::where('company_id', $companyId)
            ->whereBetween('fecha_emision', [$desde, $hasta])
            ->get();

        $compras0 = $compras->filter(fn($c) => (float)$c->total_impuesto === 0)->sum('importe_total');
        $compras12 = $compras->filter(fn($c) => (float)$c->total_impuesto > 0)->sum('total_sin_impuestos');
        $ivaPagado = $compras->sum('total_impuesto');

        // === RETENCIONES RECIBIDAS ===
        $retencionesRecibidas = Withholding::where('company_id', $companyId)
            ->where('tipo', '!=', 'emitida')
            ->whereBetween('fecha', [$desde, $hasta])
            ->sum('total_retenido');

        // === RETENCIONES EMITIDAS ===
        $retencionesEmitidas = Withholding::where('company_id', $companyId)
            ->where('tipo', 'emitida')
            ->whereBetween('fecha', [$desde, $hasta])
            ->sum('total_retenido');

        // === SALDO IVA ===
        $saldoIVA = round($ivaCobrado - $ivaPagado - $retencionesRecibidas + $retencionesEmitidas, 2);

        return [
            'periodo' => ['desde' => $r->desde, 'hasta' => $r->hasta],
            'ventas' => [
                'ventas_0' => round($ventas0, 2),
                'ventas_12' => round($ventas12, 2),
                'base_imponible_12' => round($ventas12, 2),
                'iva_cobrado' => round($ivaCobrado, 2),
                'total_ventas' => round($ventas->sum('importe_total'), 2),
            ],
            'compras' => [
                'compras_0' => round($compras0, 2),
                'compras_12' => round($compras12, 2),
                'base_imponible_12' => round($compras12, 2),
                'iva_pagado' => round($ivaPagado, 2),
                'total_compras' => round($compras->sum('importe_total'), 2),
            ],
            'retenciones' => [
                'recibidas' => round($retencionesRecibidas, 2),
                'emitidas' => round($retencionesEmitidas, 2),
            ],
            'saldo_iva' => $saldoIVA,
            'declaracion' => $saldoIVA > 0 ? 'PAGAR' : 'DEVOLUCIÓN',
            'resumen' => [
                'total_facturas' => $ventas->count(),
                'total_compras' => $compras->count(),
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

            $ventasMes = Invoice::where('company_id', $companyId)
                ->whereBetween('fecha_emision', [$desde, $hasta])->get();

            $comprasMes = Purchase::where('company_id', $companyId)
                ->whereBetween('fecha_emision', [$desde, $hasta])->get();

            $ventasMensuales[$mes] = [
                'mes' => $mes,
                'total_ventas' => round($ventasMes->sum('importe_total'), 2),
                'ventas_12' => round($ventasMes->filter(fn($v) => (float)$v->total_impuesto > 0)->sum('total_sin_impuestos'), 2),
                'ventas_0' => round($ventasMes->filter(fn($v) => (float)$v->total_impuesto === 0)->sum('importe_total'), 2),
                'iva_cobrado' => round($ventasMes->sum('total_impuesto'), 2),
                'num_facturas' => $ventasMes->count(),
            ];

            $comprasMensuales[$mes] = [
                'mes' => $mes,
                'total_compras' => round($comprasMes->sum('importe_total'), 2),
                'compras_12' => round($comprasMes->filter(fn($c) => (float)$c->total_impuesto > 0)->sum('total_sin_impuestos'), 2),
                'compras_0' => round($comprasMes->filter(fn($c) => (float)$c->total_impuesto === 0)->sum('importe_total'), 2),
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
