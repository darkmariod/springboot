<?php

namespace App\Support;

use App\Models\Advance;
use App\Models\Contact;
use App\Models\CreditApplication;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentSplit;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Withholding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Estado de cuenta de clientes y de proveedores (T3.1): por documento y resumido por contacto.
 *
 * Todo se arma desde los registros reales (facturas, notas de crédito y débito, retenciones, cobros, pagos,
 * anticipos y cruces) y el saldo de cada documento es SIEMPRE el guardado (`saldo_pendiente`): el mismo que suman
 * Cuentas por cobrar / por pagar y el chequeo contable. El desglose que se muestra tiene que cuadrar con ese saldo:
 *
 *   factura:  total + notas de débito − notas de crédito − retenciones − cobros − anticipos usados = saldo
 *   compra:   total − retenciones − pagos (con cruces) − anticipos aplicados = saldo
 *
 * Si algún día un dato viejo o raro hace que no cuadre (p. ej. una retención recibida sobre una factura que ya
 * estaba cobrada), la diferencia sale en `ajustes` y como línea "Otros ajustes" en los movimientos, para que el
 * saldo mostrado nunca se aparte del libro y la diferencia se vea en lugar de esconderse.
 *
 * Cada nota de crédito cuenta UNA vez: lo que bajó el saldo de su factura al emitirse (`aplicado_factura`) y lo que
 * después se usó como saldo a favor en otra factura (CreditApplication); lo que aún no se usó queda aparte como
 * "saldo a favor", igual que los anticipos sin aplicar. No hay fecha de vencimiento en el sistema: la antigüedad
 * se mide en días desde la emisión. Los filtros de fecha son sobre la fecha de emisión del documento.
 */
final class EstadoCuenta
{
    public const TRAMOS = ['0-30', '31-60', '61-90', '90+'];

    // =====================================================================================================
    // Clientes
    // =====================================================================================================

    /**
     * Un renglón por cliente más el total general.
     *
     * @return array{clientes:array<int,array>,totales:array,tramos:array<int,string>}
     */
    public static function resumenClientes(int $companyId, ?string $desde = null, ?string $hasta = null, ?string $buscar = null, bool $soloConSaldo = false): array
    {
        $contactIds = self::contactosQueCoinciden($companyId, $buscar);
        $documentos = self::documentosClientes($companyId, $desde, $hasta, $contactIds);
        $porContacto = $documentos->groupBy('contact_id');

        $anticipos = Advance::where('company_id', $companyId)->where('tipo', 'cliente')->where('saldo', '>', 0)
            ->selectRaw('contact_id, SUM(saldo) as total')->groupBy('contact_id')->pluck('total', 'contact_id');
        $notasAFavor = CreditNote::where('company_id', $companyId)->where('tipo', '!=', 'anulado')->where('saldo_disponible', '>', 0)
            ->selectRaw('contact_id, SUM(saldo_disponible) as total')->groupBy('contact_id')->pluck('total', 'contact_id');

        // Sin filtro de fechas también salen los clientes que solo tienen un saldo a favor sin usar
        $ids = $porContacto->keys()->all();
        if ($desde === null && $hasta === null) {
            $ids = array_unique(array_merge($ids, $anticipos->keys()->all(), $notasAFavor->keys()->all()));
        }
        $contactos = Contact::where('company_id', $companyId)->whereIn('id', $ids)
            ->when($contactIds !== null, fn ($q) => $q->whereIn('id', $contactIds))
            ->orderBy('razon_social')->orderBy('id')->get();

        $filas = [];
        foreach ($contactos as $c) {
            $fila = self::filaCliente($porContacto->get($c->id, collect()), (float) ($anticipos[$c->id] ?? 0), (float) ($notasAFavor[$c->id] ?? 0));
            if ($soloConSaldo && abs($fila['saldo']) < 0.005 && $fila['anticipos_sin_aplicar'] < 0.005 && $fila['saldo_favor_nc'] < 0.005) {
                continue;
            }
            $filas[] = ['contact_id' => $c->id, 'cliente' => $c->razon_social, 'identificacion' => $c->identificacion] + $fila;
        }

        return ['clientes' => $filas, 'totales' => self::totalizar($filas, true), 'tramos' => self::TRAMOS];
    }

    /**
     * El estado de cuenta de un cliente: cada factura con sus notas, retenciones y cobros, la lista cronológica de
     * movimientos con saldo corrido, y aparte los anticipos y notas de crédito que todavía no se han usado.
     */
    public static function detalleCliente(int $companyId, Contact $contacto, ?string $desde = null, ?string $hasta = null): array
    {
        $documentos = self::documentosClientes($companyId, $desde, $hasta, [$contacto->id]);

        $anticipos = Advance::where('company_id', $companyId)->where('contact_id', $contacto->id)->where('tipo', 'cliente')
            ->where('saldo', '>', 0)->orderBy('fecha')->orderBy('id')->get()
            ->map(fn (Advance $a) => ['id' => $a->id, 'fecha' => self::fecha($a->fecha), 'monto' => self::r2($a->monto),
                'saldo' => self::r2($a->saldo), 'nota' => $a->nota])->values()->all();
        $notasAFavor = CreditNote::where('company_id', $companyId)->where('contact_id', $contacto->id)->where('tipo', '!=', 'anulado')
            ->where('saldo_disponible', '>', 0)->orderBy('fecha')->orderBy('id')->get()
            ->map(fn (CreditNote $n) => ['id' => $n->id, 'numero' => self::numeroNota($n), 'fecha' => self::fecha($n->fecha),
                'total' => self::r2($n->importe_total), 'saldo' => self::r2($n->saldo_disponible), 'motivo' => $n->motivo])->values()->all();

        $resumen = self::filaCliente($documentos, array_sum(array_column($anticipos, 'saldo')), array_sum(array_column($notasAFavor, 'saldo')));

        return [
            'cliente' => ['id' => $contacto->id, 'razon_social' => $contacto->razon_social, 'identificacion' => $contacto->identificacion],
            'documentos' => $documentos->map(fn (array $d) => array_diff_key($d, ['movimientos' => 1, 'contact_id' => 1]))->values()->all(),
            'movimientos' => self::movimientos($documentos),
            'anticipos_sin_aplicar' => $anticipos,
            'notas_credito_a_favor' => $notasAFavor,
            'resumen' => $resumen,
        ];
    }

    /**
     * Facturas vigentes (no anuladas) con todo su desglose. Los documentos que no son facturas de venta (notas de
     * débito, que comparten la tabla) no salen como documento: cargan valor a su factura.
     *
     * @param  array<int,int>|null  $contactIds  null = todos los clientes de la empresa
     * @return Collection<int,array>
     */
    private static function documentosClientes(int $companyId, ?string $desde, ?string $hasta, ?array $contactIds): Collection
    {
        $facturas = Invoice::soloFacturas()->where('company_id', $companyId)->where('estado', '!=', 'anulado')
            ->when($contactIds !== null, fn ($q) => $q->whereIn('contact_id', $contactIds))
            ->when($desde, fn ($q, $d) => $q->whereDate('fecha_emision', '>=', $d))
            ->when($hasta, fn ($q, $h) => $q->whereDate('fecha_emision', '<=', $h))
            ->orderBy('fecha_emision')->orderBy('id')->get();
        if ($facturas->isEmpty()) {
            return collect();
        }
        $ids = $facturas->pluck('id')->all();

        $notasDebito = self::porDocumento($ids, fn (array $lote) => Invoice::where('company_id', $companyId)
            ->whereIn('tipo_comprobante', Invoice::TIPOS_NOTA_DEBITO)->where('estado', '!=', 'anulado')
            ->whereIn('factura_referencia_id', $lote)->orderBy('fecha_emision')->orderBy('id')->get(), 'factura_referencia_id');
        $notasCredito = self::porDocumento($ids, fn (array $lote) => CreditNote::where('company_id', $companyId)->where('tipo', '!=', 'anulado')
            ->whereIn('invoice_id', $lote)->orderBy('fecha')->orderBy('id')->get(), 'invoice_id');
        $aplicaciones = self::porDocumento($ids, fn (array $lote) => CreditApplication::whereIn('invoice_id', $lote)->orderBy('fecha')->orderBy('id')->get(), 'invoice_id');
        $retenciones = self::porDocumento($ids, fn (array $lote) => Withholding::where('company_id', $companyId)->where('tipo', '!=', 'emitida')
            ->whereIn('invoice_id', $lote)->orderBy('fecha')->orderBy('id')->get(), 'invoice_id');
        $cobros = self::porDocumento($ids, fn (array $lote) => InvoicePayment::whereIn('invoice_id', $lote)->orderBy('fecha')->orderBy('id')->get(), 'invoice_id');

        // Las notas de crédito cuyo saldo a favor se usó en estas facturas (pueden ser de otra factura)
        $morphNota = (new CreditNote)->getMorphClass();
        $morphAnticipo = (new Advance)->getMorphClass();
        $notasUsadas = CreditNote::where('company_id', $companyId)
            ->whereIn('id', $aplicaciones->flatten(1)->where('origen_type', $morphNota)->pluck('origen_id')->unique()->all())->get()->keyBy('id');

        return $facturas->map(function (Invoice $f) use ($notasDebito, $notasCredito, $aplicaciones, $retenciones, $cobros, $notasUsadas, $morphNota, $morphAnticipo) {
            $total = self::r2($f->importe_total);
            $fecha = self::fecha($f->fecha_emision);
            $numero = (string) $f->numero;
            $movimientos = [self::mov($fecha, 'factura', $numero, $numero, 'Factura', $total, 0.0, 0)];

            // Una factura que no es a crédito nace cobrada: el cobro al contado cuenta como un cobro más
            $contado = ($f->forma_pago ?? 'credito') === 'credito' ? 0.0 : $total;
            $listaCobros = [];
            if ($contado > 0) {
                $listaCobros[] = ['fecha' => $fecha, 'forma' => $f->forma_pago, 'forma_label' => self::formaLabel($f->forma_pago), 'monto' => $contado, 'detalle' => 'Cobro al contado'];
            }
            foreach ($cobros->get($f->id, collect()) as $p) {
                $listaCobros[] = ['fecha' => self::fecha($p->fecha), 'forma' => $p->forma_pago, 'forma_label' => self::formaLabel($p->forma_pago),
                    'monto' => self::r2($p->monto), 'detalle' => $p->nota];
            }
            foreach ($listaCobros as $c) {
                $esCruce = self::esCruce($c['forma']);
                $movimientos[] = self::mov($c['fecha'], $esCruce ? 'cruce' : 'cobro', $numero, $numero,
                    $c['detalle'] ?: ($esCruce ? 'Cruce de saldos' : 'Cobro').' ('.$c['forma_label'].')', 0.0, $c['monto'], 4);
            }

            $listaDebito = [];
            foreach ($notasDebito->get($f->id, collect()) as $n) {
                $item = ['id' => $n->id, 'numero' => (string) $n->numero, 'fecha' => self::fecha($n->fecha_emision), 'total' => self::r2($n->importe_total),
                    'interna' => $n->tipo_comprobante === 'nota_debito_interna'];
                $listaDebito[] = $item;
                $movimientos[] = self::mov($item['fecha'], 'nota_debito', $item['numero'], $numero, 'Nota de débito'.($item['interna'] ? ' interna' : ''), $item['total'], 0.0, 1);
            }

            $listaCredito = [];
            foreach ($notasCredito->get($f->id, collect()) as $n) {
                $item = ['id' => $n->id, 'numero' => self::numeroNota($n), 'fecha' => self::fecha($n->fecha), 'total' => self::r2($n->importe_total),
                    'aplicado' => self::r2($n->aplicado_factura), 'interna' => self::notaInterna($n), 'via' => 'emision'];
                $listaCredito[] = $item;
                if ($item['aplicado'] > 0) {
                    $movimientos[] = self::mov($item['fecha'], 'nota_credito', $item['numero'], $numero, 'Nota de crédito'.($item['interna'] ? ' interna' : ''), 0.0, $item['aplicado'], 2);
                }
            }

            $listaAnticipos = [];
            foreach ($aplicaciones->get($f->id, collect()) as $a) {
                $monto = self::r2($a->monto);
                if ($a->origen_type === $morphNota) {
                    $nota = $notasUsadas->get($a->origen_id);
                    if (! $nota || $nota->tipo === 'anulado') {
                        continue; // anulada: el saldo ya volvió a la factura
                    }
                    $item = ['id' => $nota->id, 'numero' => self::numeroNota($nota), 'fecha' => self::fecha($a->fecha), 'total' => self::r2($nota->importe_total),
                        'aplicado' => $monto, 'interna' => self::notaInterna($nota), 'via' => 'saldo_favor'];
                    $listaCredito[] = $item;
                    $movimientos[] = self::mov($item['fecha'], 'nota_credito', $item['numero'], $numero, 'Saldo a favor de nota de crédito', 0.0, $monto, 2);
                } elseif ($a->origen_type === $morphAnticipo) {
                    $documento = 'ANT-'.$a->origen_id;
                    $listaAnticipos[] = ['fecha' => self::fecha($a->fecha), 'monto' => $monto, 'documento' => $documento];
                    $movimientos[] = self::mov(self::fecha($a->fecha), 'anticipo', $documento, $numero, 'Uso de anticipo', 0.0, $monto, 5);
                }
            }

            $listaRetenciones = [];
            foreach ($retenciones->get($f->id, collect()) as $w) {
                $item = ['numero' => (string) $w->numero, 'fecha' => self::fecha($w->fecha), 'valor' => self::r2($w->total_retenido)];
                $listaRetenciones[] = $item;
                $movimientos[] = self::mov($item['fecha'] ?? $fecha, 'retencion', $item['numero'], $numero, 'Retención recibida', 0.0, $item['valor'], 3);
            }

            $ndTotal = self::r2(array_sum(array_column($listaDebito, 'total')));
            $ncTotal = self::r2(array_sum(array_column($listaCredito, 'aplicado')));
            $retTotal = self::r2(array_sum(array_column($listaRetenciones, 'valor')));
            $cobrosTotal = self::r2(array_sum(array_column($listaCobros, 'monto')));
            $anticiposTotal = self::r2(array_sum(array_column($listaAnticipos, 'monto')));
            $cruces = self::r2(array_sum(array_map(fn ($c) => self::esCruce($c['forma']) ? $c['monto'] : 0, $listaCobros)));

            $saldo = self::r2($f->saldo_pendiente);
            $ajustes = self::r2($saldo - ($total + $ndTotal - $ncTotal - $retTotal - $cobrosTotal - $anticiposTotal));
            if (abs($ajustes) >= 0.005) {
                $movimientos[] = self::mov($fecha, 'ajuste', $numero, $numero, 'Otros ajustes (diferencia con el saldo guardado)', max($ajustes, 0.0), max(-$ajustes, 0.0), 6);
            }
            $dias = self::dias($fecha);

            return [
                'id' => $f->id, 'contact_id' => $f->contact_id, 'numero' => $numero, 'fecha' => $fecha, 'dias' => $dias, 'tramo' => self::tramo($dias),
                'total' => $total,
                'notas_credito' => $listaCredito, 'notas_debito' => $listaDebito, 'retenciones' => $listaRetenciones,
                'cobros' => $listaCobros, 'anticipos' => $listaAnticipos,
                'notas_debito_total' => $ndTotal, 'notas_credito_total' => $ncTotal, 'retenciones_total' => $retTotal,
                'cobros_total' => $cobrosTotal, 'cruces_total' => $cruces, 'anticipos_total' => $anticiposTotal,
                'ajustes' => $ajustes, 'saldo' => $saldo,
                'movimientos' => $movimientos,
            ];
        })->values();
    }

    /** Las sumas de un cliente a partir de sus documentos, más lo que tiene a favor sin usar. */
    private static function filaCliente(Collection $docs, float $anticiposSinAplicar, float $notasAFavor): array
    {
        $saldo = self::r2($docs->sum('saldo'));
        $fila = [
            'documentos' => $docs->count(),
            'facturado' => self::r2($docs->sum('total')),
            'notas_debito' => self::r2($docs->sum('notas_debito_total')),
            'notas_credito' => self::r2($docs->sum('notas_credito_total')),
            'retenciones' => self::r2($docs->sum('retenciones_total')),
            'cobros' => self::r2($docs->sum('cobros_total')),
            'cruces' => self::r2($docs->sum('cruces_total')),
            'anticipos_aplicados' => self::r2($docs->sum('anticipos_total')),
            'ajustes' => self::r2($docs->sum('ajustes')),
            'saldo' => $saldo,
            'saldo_favor_nc' => self::r2($notasAFavor),
            'anticipos_sin_aplicar' => self::r2($anticiposSinAplicar),
        ];
        $fila['saldo_neto'] = self::r2($saldo - $fila['saldo_favor_nc'] - $fila['anticipos_sin_aplicar']);
        $fila['antiguedad'] = self::antiguedad($docs);

        return $fila;
    }

    // =====================================================================================================
    // Proveedores
    // =====================================================================================================

    /**
     * Un renglón por proveedor más el total general.
     *
     * @return array{proveedores:array<int,array>,totales:array,tramos:array<int,string>}
     */
    public static function resumenProveedores(int $companyId, ?string $desde = null, ?string $hasta = null, ?string $buscar = null, bool $soloConSaldo = false): array
    {
        $contactIds = self::contactosQueCoinciden($companyId, $buscar);
        $documentos = self::documentosProveedores($companyId, $desde, $hasta, $contactIds);
        $porContacto = $documentos->groupBy('contact_id');

        $anticipos = Advance::where('company_id', $companyId)->where('tipo', 'proveedor')->where('saldo', '>', 0)
            ->selectRaw('contact_id, SUM(saldo) as total')->groupBy('contact_id')->pluck('total', 'contact_id');

        $ids = $porContacto->keys()->all();
        if ($desde === null && $hasta === null) {
            $ids = array_unique(array_merge($ids, $anticipos->keys()->all()));
        }
        $contactos = Contact::where('company_id', $companyId)->whereIn('id', $ids)
            ->when($contactIds !== null, fn ($q) => $q->whereIn('id', $contactIds))
            ->orderBy('razon_social')->orderBy('id')->get();

        $filas = [];
        foreach ($contactos as $c) {
            $fila = self::filaProveedor($porContacto->get($c->id, collect()), (float) ($anticipos[$c->id] ?? 0));
            if ($soloConSaldo && abs($fila['saldo']) < 0.005 && $fila['anticipos_sin_aplicar'] < 0.005) {
                continue;
            }
            $filas[] = ['contact_id' => $c->id, 'proveedor' => $c->razon_social, 'identificacion' => $c->identificacion] + $fila;
        }

        return ['proveedores' => $filas, 'totales' => self::totalizar($filas, false), 'tramos' => self::TRAMOS];
    }

    /** El estado de cuenta de un proveedor: cada compra con sus retenciones y pagos, movimientos con saldo corrido y anticipos sin aplicar. */
    public static function detalleProveedor(int $companyId, Contact $contacto, ?string $desde = null, ?string $hasta = null): array
    {
        $documentos = self::documentosProveedores($companyId, $desde, $hasta, [$contacto->id]);

        $anticipos = Advance::where('company_id', $companyId)->where('contact_id', $contacto->id)->where('tipo', 'proveedor')
            ->where('saldo', '>', 0)->orderBy('fecha')->orderBy('id')->get()
            ->map(fn (Advance $a) => ['id' => $a->id, 'fecha' => self::fecha($a->fecha), 'monto' => self::r2($a->monto),
                'saldo' => self::r2($a->saldo), 'nota' => $a->nota])->values()->all();

        return [
            'proveedor' => ['id' => $contacto->id, 'razon_social' => $contacto->razon_social, 'identificacion' => $contacto->identificacion],
            'documentos' => $documentos->map(fn (array $d) => array_diff_key($d, ['movimientos' => 1, 'contact_id' => 1]))->values()->all(),
            'movimientos' => self::movimientos($documentos),
            'anticipos_sin_aplicar' => $anticipos,
            'resumen' => self::filaProveedor($documentos, array_sum(array_column($anticipos, 'saldo'))),
        ];
    }

    /**
     * Compras con todo su desglose.
     *
     * @param  array<int,int>|null  $contactIds
     * @return Collection<int,array>
     */
    private static function documentosProveedores(int $companyId, ?string $desde, ?string $hasta, ?array $contactIds): Collection
    {
        $compras = Purchase::where('company_id', $companyId)
            ->when($contactIds !== null, fn ($q) => $q->whereIn('contact_id', $contactIds))
            ->when($desde, fn ($q, $d) => $q->whereDate('fecha_emision', '>=', $d))
            ->when($hasta, fn ($q, $h) => $q->whereDate('fecha_emision', '<=', $h))
            ->orderBy('fecha_emision')->orderBy('id')->get();
        if ($compras->isEmpty()) {
            return collect();
        }
        $ids = $compras->pluck('id')->all();

        $retenciones = self::porDocumento($ids, fn (array $lote) => Withholding::where('company_id', $companyId)->where('tipo', 'emitida')
            ->whereIn('purchase_id', $lote)->orderBy('fecha')->orderBy('id')->get(), 'purchase_id');
        $pagos = self::porDocumento($ids, fn (array $lote) => PurchasePayment::whereIn('purchase_id', $lote)->orderBy('fecha')->orderBy('id')->get(), 'purchase_id');
        // El documento contrario de cada cruce y el anticipo usado quedan en el reparto de pagos de la compra
        $repartos = PaymentSplit::where('company_id', $companyId)->where('pagable_type', (new Purchase)->getMorphClass())
            ->whereIn('pagable_id', $ids)->whereIn('tipo', ['cruce_saldos', 'anticipo'])->orderBy('id')->get()
            ->groupBy(fn (PaymentSplit $s) => $s->pagable_id.'|'.$s->tipo);

        return $compras->map(function (Purchase $p) use ($retenciones, $pagos, $repartos) {
            $total = self::r2($p->importe_total);
            $fecha = self::fecha($p->fecha_emision);
            $numero = (string) $p->numero;
            $movimientos = [self::mov($fecha, 'compra', $numero, $numero, 'Compra', $total, 0.0, 0)];

            // Una retención puede traer varias líneas (renta e IVA) con el mismo número: sale como un solo comprobante
            $listaRetenciones = [];
            foreach ($retenciones->get($p->id, collect())->groupBy('numero') as $numeroRet => $filas) {
                $item = ['numero' => (string) $numeroRet, 'fecha' => self::fecha($filas->first()->fecha), 'valor' => self::r2($filas->sum('total_retenido'))];
                $listaRetenciones[] = $item;
                $movimientos[] = self::mov($item['fecha'] ?? $fecha, 'retencion', $item['numero'], $numero, 'Retención emitida', 0.0, $item['valor'], 1);
            }

            $listaPagos = [];
            $listaAnticipos = [];
            $contador = [];
            foreach ($pagos->get($p->id, collect()) as $pago) {
                $forma = (string) $pago->forma_pago;
                $esAnticipo = $forma === 'anticipo';
                $esCruce = self::esCruce($forma);
                $monto = self::r2($pago->monto);
                $detalle = $pago->cheque_numero ? 'Cheque '.$pago->cheque_numero : null;
                $documento = null;
                if ($esAnticipo || $esCruce) {
                    $tipo = $esAnticipo ? 'anticipo' : 'cruce_saldos';
                    $n = $contador[$tipo] = ($contador[$tipo] ?? -1) + 1;
                    $documento = $repartos->get($p->id.'|'.$tipo, collect())->values()->get($n)?->documento;
                    $detalle = $esAnticipo ? 'Anticipo aplicado' : 'Cruce con factura '.($documento ?? '');
                }
                $item = ['fecha' => self::fecha($pago->fecha), 'forma' => $forma, 'forma_label' => self::formaLabel($forma), 'monto' => $monto,
                    'detalle' => $detalle, 'documento' => $documento];
                if ($esAnticipo) {
                    $listaAnticipos[] = $item;
                } else {
                    $listaPagos[] = $item;
                }
                $movimientos[] = self::mov($item['fecha'], $esAnticipo ? 'anticipo' : ($esCruce ? 'cruce' : 'pago'), $documento ?: $numero, $numero,
                    ($detalle ?: 'Pago').($esAnticipo || $esCruce ? '' : ' ('.$item['forma_label'].')'), 0.0, $monto, 3);
            }

            $retTotal = self::r2(array_sum(array_column($listaRetenciones, 'valor')));
            $pagosTotal = self::r2(array_sum(array_column($listaPagos, 'monto')));
            $anticiposTotal = self::r2(array_sum(array_column($listaAnticipos, 'monto')));
            $cruces = self::r2(array_sum(array_map(fn ($c) => self::esCruce($c['forma']) ? $c['monto'] : 0, $listaPagos)));

            $saldo = self::r2($p->saldo_pendiente);
            $ajustes = self::r2($saldo - ($total - $retTotal - $pagosTotal - $anticiposTotal));
            if (abs($ajustes) >= 0.005) {
                $movimientos[] = self::mov($fecha, 'ajuste', $numero, $numero, 'Otros ajustes (diferencia con el saldo guardado)', max($ajustes, 0.0), max(-$ajustes, 0.0), 4);
            }
            $dias = self::dias($fecha);

            return [
                'id' => $p->id, 'contact_id' => $p->contact_id, 'numero' => $numero, 'fecha' => $fecha, 'dias' => $dias, 'tramo' => self::tramo($dias),
                'total' => $total,
                'retenciones' => $listaRetenciones, 'pagos' => $listaPagos, 'anticipos' => $listaAnticipos,
                'retenciones_total' => $retTotal, 'pagos_total' => $pagosTotal, 'cruces_total' => $cruces, 'anticipos_total' => $anticiposTotal,
                'ajustes' => $ajustes, 'saldo' => $saldo,
                'movimientos' => $movimientos,
            ];
        })->values();
    }

    private static function filaProveedor(Collection $docs, float $anticiposSinAplicar): array
    {
        $saldo = self::r2($docs->sum('saldo'));
        $fila = [
            'documentos' => $docs->count(),
            'comprado' => self::r2($docs->sum('total')),
            'retenciones' => self::r2($docs->sum('retenciones_total')),
            'pagos' => self::r2($docs->sum('pagos_total')),
            'cruces' => self::r2($docs->sum('cruces_total')),
            'anticipos_aplicados' => self::r2($docs->sum('anticipos_total')),
            'ajustes' => self::r2($docs->sum('ajustes')),
            'saldo' => $saldo,
            'anticipos_sin_aplicar' => self::r2($anticiposSinAplicar),
        ];
        $fila['saldo_neto'] = self::r2($saldo - $fila['anticipos_sin_aplicar']);
        $fila['antiguedad'] = self::antiguedad($docs);

        return $fila;
    }

    // =====================================================================================================
    // Comunes
    // =====================================================================================================

    /** Ids de los contactos de la empresa que coinciden con la búsqueda (nombre, nombre comercial o identificación); null si no se buscó. */
    private static function contactosQueCoinciden(int $companyId, ?string $buscar): ?array
    {
        $buscar = trim((string) $buscar);
        if ($buscar === '') {
            return null;
        }
        $patron = '%'.str_replace(['%', '_'], ['\%', '\_'], $buscar).'%';

        return Contact::where('company_id', $companyId)->where(fn ($q) => $q
            ->where('razon_social', 'like', $patron)->orWhere('nombre_comercial', 'like', $patron)->orWhere('identificacion', 'like', $patron))
            ->pluck('id')->all();
    }

    /**
     * Carga por lotes (SQLite no admite listas de ids enormes) y agrupa por el documento al que pertenecen.
     *
     * @param  array<int,int>  $ids
     * @return Collection<int|string,Collection>
     */
    private static function porDocumento(array $ids, callable $consulta, string $campo): Collection
    {
        $todo = collect();
        foreach (array_chunk($ids, 500) as $lote) {
            $todo = $todo->concat($consulta($lote));
        }

        return $todo->groupBy($campo);
    }

    /** Las líneas de todos los documentos en orden cronológico, con el saldo corrido. */
    private static function movimientos(Collection $documentos): array
    {
        $lineas = [];
        $secuencia = 0;
        foreach ($documentos as $d) {
            foreach ($d['movimientos'] as $m) {
                $lineas[] = $m + ['_n' => $secuencia++];
            }
        }
        usort($lineas, fn ($a, $b) => [$a['fecha'], $a['_orden'], $a['_n']] <=> [$b['fecha'], $b['_orden'], $b['_n']]);

        $saldo = 0.0;
        $salida = [];
        foreach ($lineas as $m) {
            $saldo = round($saldo + $m['cargo'] - $m['abono'], 2);
            $salida[] = ['fecha' => $m['fecha'], 'tipo' => $m['tipo'], 'documento' => $m['documento'], 'referencia' => $m['referencia'],
                'detalle' => $m['detalle'], 'cargo' => $m['cargo'], 'abono' => $m['abono'], 'saldo' => $saldo];
        }

        return $salida;
    }

    /** Una línea de movimiento; `_orden` desempata el mismo día (primero el documento, luego notas, retenciones y cobros). */
    private static function mov(?string $fecha, string $tipo, string $documento, string $referencia, string $detalle, float $cargo, float $abono, int $orden): array
    {
        return ['fecha' => $fecha ?? '', 'tipo' => $tipo, 'documento' => $documento, 'referencia' => $referencia, 'detalle' => $detalle,
            'cargo' => self::r2($cargo), 'abono' => self::r2($abono), '_orden' => $orden];
    }

    /** Suma de los renglones de contactos; `$cliente` elige las columnas propias de clientes o de proveedores. */
    private static function totalizar(array $filas, bool $cliente): array
    {
        $campos = $cliente
            ? ['documentos', 'facturado', 'notas_debito', 'notas_credito', 'retenciones', 'cobros', 'cruces', 'anticipos_aplicados', 'ajustes', 'saldo', 'saldo_favor_nc', 'anticipos_sin_aplicar', 'saldo_neto']
            : ['documentos', 'comprado', 'retenciones', 'pagos', 'cruces', 'anticipos_aplicados', 'ajustes', 'saldo', 'anticipos_sin_aplicar', 'saldo_neto'];
        $totales = [];
        foreach ($campos as $c) {
            $totales[$c] = $c === 'documentos' ? (int) array_sum(array_column($filas, $c)) : self::r2(array_sum(array_column($filas, $c)));
        }
        $totales['antiguedad'] = array_fill_keys(self::TRAMOS, 0.0);
        foreach ($filas as $f) {
            foreach (self::TRAMOS as $t) {
                $totales['antiguedad'][$t] = self::r2($totales['antiguedad'][$t] + $f['antiguedad'][$t]);
            }
        }

        return $totales;
    }

    /** Saldo de los documentos con deuda repartido por días desde la emisión (no hay fecha de vencimiento). */
    private static function antiguedad(Collection $docs): array
    {
        $tramos = array_fill_keys(self::TRAMOS, 0.0);
        foreach ($docs as $d) {
            if ($d['saldo'] > 0) {
                $tramos[$d['tramo']] += $d['saldo'];
            }
        }

        return array_map(fn ($v) => self::r2($v), $tramos);
    }

    public static function tramo(int $dias): string
    {
        return match (true) { $dias <= 30 => '0-30', $dias <= 60 => '31-60', $dias <= 90 => '61-90', default => '90+' };
    }

    /** Días desde la emisión hasta hoy (nunca negativo). Carbon 3 devuelve el signo según el orden, por eso se pide con signo explícito. */
    public static function dias(?string $fecha): int
    {
        if (! $fecha) {
            return 0;
        }

        return max(0, (int) floor(Carbon::parse($fecha)->startOfDay()->diffInDays(Carbon::today(), false)));
    }

    private static function fecha(mixed $valor): ?string
    {
        return $valor ? Carbon::parse($valor)->toDateString() : null;
    }

    private static function r2(mixed $valor): float
    {
        return round((float) $valor, 2);
    }

    private static function esCruce(?string $forma): bool
    {
        return in_array((string) $forma, ['cruce_saldos', 'cruce'], true);
    }

    private static function formaLabel(?string $forma): string
    {
        $forma = (string) $forma;
        $etiquetas = ['cruce' => 'Cruce de saldos', 'cheque' => 'Cheque', 'anticipo' => 'Anticipo', 'credito' => 'Crédito', 'tarjeta' => 'Tarjeta'];

        return config("formas_pago.$forma.label") ?? $etiquetas[$forma] ?? ucfirst(str_replace('_', ' ', $forma));
    }

    private static function notaInterna(CreditNote $n): bool
    {
        return $n->tipo === 'interna' || str_starts_with((string) $n->numero, 'NCI-');
    }

    private static function numeroNota(CreditNote $n): string
    {
        return $n->numero ?: 'NC #'.$n->id;
    }
}
