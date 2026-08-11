<?php
namespace App\Http\Controllers;

use App\Models\BankMovement;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SriDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller {
    /** Resumen ejecutivo del negocio para la pestaña Inicio. */
    public function resumen(Request $r) {
        $companyId = (int) $r->input('company_id');
        $hoy = Carbon::today();
        $inicioMes = $hoy->copy()->startOfMonth();
        $finHoy = $hoy->copy()->endOfDay();
        $mesAntIni = $inicioMes->copy()->subMonth();
        $mesAntFin = $inicioMes->copy()->subSecond();

        // ── Ventas del mes vs mes anterior (sin anuladas) ──
        $ventasMes = (float) Invoice::where('company_id', $companyId)
            ->where('estado', '!=', 'anulado')
            ->whereBetween('fecha_emision', [$inicioMes, $finHoy])
            ->sum('importe_total');
        $ventasMesAnterior = (float) Invoice::where('company_id', $companyId)
            ->where('estado', '!=', 'anulado')
            ->whereBetween('fecha_emision', [$mesAntIni, $mesAntFin])
            ->sum('importe_total');
        $ventasVariacion = $ventasMesAnterior > 0
            ? round((($ventasMes - $ventasMesAnterior) / $ventasMesAnterior) * 100, 1)
            : null;

        // ── Cobrado del mes (pagos; scoped por empresa vía factura) ──
        $cobradoMes = (float) InvoicePayment::join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->where('invoices.company_id', $companyId)
            ->whereBetween('invoice_payments.fecha', [$inicioMes, $hoy])
            ->sum('invoice_payments.monto');

        // ── Cartera abierta ──
        $porCobrar = (float) Invoice::where('company_id', $companyId)
            ->where('estado', '!=', 'anulado')->where('saldo_pendiente', '>', 0)
            ->sum('saldo_pendiente');
        $facturasPorCobrar = Invoice::where('company_id', $companyId)
            ->where('estado', '!=', 'anulado')->where('saldo_pendiente', '>', 0)->count();

        // ── Documentos recientes (5 últimas facturas) ──
        $docs = Invoice::with('contact:id,razon_social', 'sriDocument:id,documentable_id,estado')
            ->where('company_id', $companyId)
            ->orderByDesc('fecha_emision')->limit(5)->get()
            ->map(fn ($i) => [
                'tipo' => 'Factura',
                'numero' => $i->numero,
                'cliente' => $i->contact?->razon_social ?? '—',
                'valor' => (float) $i->importe_total,
                'estado_sri' => $this->estadoSri($i->sriDocument?->estado),
                'fecha' => optional($i->fecha_emision)->format('d/m/Y'),
            ]);

        // ── Acciones pendientes ──
        $sriPendientes = SriDocument::where('company_id', $companyId)
            ->where('estado', '!=', 'AUTORIZADO')->count();
        $conciliaciones = BankMovement::where('company_id', $companyId)
            ->where('conciliado', false)->count();
        $facturasVencidas = Invoice::where('company_id', $companyId)
            ->where('estado', '!=', 'anulado')->where('saldo_pendiente', '>', 0)
            ->where('fecha_emision', '<', $hoy)->count();

        // ── Actividad reciente (autorizaciones, cobros, inventario) ──
        $actividad = $this->actividad($companyId, $r->user()?->name ?? 'Usuario');

        // ── Serie de ventas últimos 7 días (sparkline) ──
        $ventasSerie = [];
        for ($i = 6; $i >= 0; $i--) {
            $dia = $hoy->copy()->subDays($i);
            $ventasSerie[] = [
                'fecha' => $dia->toDateString(),
                'total' => (float) Invoice::where('company_id', $companyId)
                    ->where('estado', '!=', 'anulado')
                    ->whereBetween('fecha_emision', [$dia->copy()->startOfDay(), $dia->copy()->endOfDay()])
                    ->sum('importe_total'),
            ];
        }

        return [
            'periodo' => ['desde' => $inicioMes->toDateString(), 'hasta' => $hoy->toDateString()],
            'ventas_mes' => $ventasMes,
            'ventas_mes_anterior' => $ventasMesAnterior,
            'ventas_variacion_pct' => $ventasVariacion,
            'cobrado_mes' => $cobradoMes,
            'cobrado_pct' => $ventasMes > 0 ? round(($cobradoMes / $ventasMes) * 100, 1) : null,
            'por_cobrar' => $porCobrar,
            'facturas_por_cobrar' => $facturasPorCobrar,
            'ventas_serie' => $ventasSerie,
            'documentos' => $docs->values(),
            'acciones' => [
                ['key' => 'sri', 'etiqueta' => 'Documentos SRI por autorizar', 'cantidad' => $sriPendientes],
                ['key' => 'conciliaciones', 'etiqueta' => 'Movimientos bancarios sin conciliar', 'cantidad' => $conciliaciones],
                ['key' => 'vencidas', 'etiqueta' => 'Facturas vencidas por cobrar', 'cantidad' => $facturasVencidas],
            ],
            'actividad' => $actividad,
        ];
    }

    private function estadoSri(?string $estado): array {
        return match (strtoupper((string) $estado)) {
            'AUTORIZADO' => ['label' => 'AUTORIZADO', 'chip' => 'good'],
            'ENVIADO', 'FIRMADO', 'GENERADO' => ['label' => 'EN PROCESO', 'chip' => 'warn'],
            default => ['label' => $estado ? strtoupper($estado) : 'SIN ENVIAR', 'chip' => 'warn'],
        };
    }

    private function actividad(int $companyId, string $usuario): array {
        $ev = [];
        foreach (SriDocument::where('company_id', $companyId)->where('estado', 'AUTORIZADO')
            ->latest('updated_at')->limit(4)->get() as $d) {
            $ev[] = ['tipo' => 'sri', 'texto' => 'Comprobante SRI autorizado',
                'detalle' => $d->tipo_comprobante, 'cuando' => optional($d->updated_at)->toIso8601String(), 'usuario' => $usuario];
        }
        foreach (InvoicePayment::join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->select('invoice_payments.*')
            ->where('invoices.company_id', $companyId)
            ->latest('invoice_payments.created_at')->limit(4)->get() as $p) {
            $ev[] = ['tipo' => 'cobro', 'texto' => 'Cobro de $'.number_format((float) $p->monto, 2).' registrado',
                'detalle' => $p->forma_pago, 'cuando' => optional($p->created_at)->toIso8601String(), 'usuario' => $usuario];
        }
        foreach (InventoryMovement::where('company_id', $companyId)
            ->latest('created_at')->limit(4)->get() as $m) {
            $ev[] = ['tipo' => 'inventario', 'texto' => 'Movimiento de inventario',
                'detalle' => $m->tipo.' · '.($m->concepto ?? ''), 'cuando' => optional($m->created_at)->toIso8601String(), 'usuario' => $usuario];
        }
        usort($ev, fn ($a, $b) => strcmp((string) $b['cuando'], (string) $a['cuando']));
        return array_slice($ev, 0, 8);
    }
}
