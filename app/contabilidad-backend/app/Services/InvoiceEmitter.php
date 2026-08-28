<?php

namespace App\Services;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmissionPoint;
use App\Models\Invoice;
use App\Models\Product;

class InvoiceEmitter
{
    private const SRI_FORMA_PAGO = ['efectivo' => '01', 'transferencia' => '20', 'tarjeta' => '19', 'credito' => '20'];

    public function __construct(
        private DocumentCalculator $calculator,
        private EmitirSriDocument $emitir,
        private RegisterInventoryMovement $inventario,
        private GenerateInvoiceJournalEntry $asiento,
    ) {}

    public function emit(Company $company, Contact $contact, array $items, string $formaPago = 'efectivo', ?int $emissionPointId = null, ?int $branchId = null): Invoice
    {
        return \DB::transaction(function () use ($company, $contact, $items, $formaPago, $emissionPointId, $branchId) {
            $formaPago = array_key_exists($formaPago, self::SRI_FORMA_PAGO) ? $formaPago : 'efectivo';
            // El establecimiento sale de la sucursal; si no se indica, de la matriz.
            $estab = $company->estab;
            $ptoEmi = $company->pto_emi;
            $branch = null;
            if ($emissionPointId) {
                $ep = EmissionPoint::find($emissionPointId);
                if ($ep && $ep->company_id === $company->id) {
                    $estab = $ep->estab ?? $estab;
                    $ptoEmi = $ep->punto ?? $ptoEmi;
                    $branchId = $branchId ?: $ep->branch_id;
                }
            }
            if ($branchId) {
                $branch = \App\Models\Branch::find($branchId);
                if ($branch && $branch->company_id === $company->id) {
                    $estab = $branch->estab;
                }
            } elseif (! $emissionPointId) {
                $branch = \App\Models\Branch::where('company_id', $company->id)
                    ->orderByDesc('es_matriz')->first();
                if ($branch) { $estab = $branch->estab; $branchId = $branch->id; }
            }
            $totals = $this->calculator->fromItems($items);
            $invoice = Invoice::create([
                'company_id' => $company->id, 'contact_id' => $contact->id, 'branch_id' => $branchId,
                'numero' => sprintf('%s-%s-%09d', $estab, $ptoEmi, $company->secuencial),
                'items' => $items, 'total_sin_impuestos' => $totals['total_sin_impuestos'],
                'total_impuesto' => $totals['total_impuesto'], 'importe_total' => $totals['importe_total'],
                'forma_pago' => $formaPago, 'saldo_pendiente' => $formaPago === 'credito' ? $totals['importe_total'] : 0,
                'estado' => 'emitida', 'fecha_emision' => now(),
            ]);
            // Un cobro con tarjeta no es dinero en caja todavía: queda pendiente
            // hasta que el procesador lo deposite (conciliación de tarjetas).
            if ($formaPago === 'tarjeta') {
                \App\Models\CardTransaction::create([
                    'company_id' => $company->id,
                    'invoice_id' => $invoice->id,
                    'fecha'      => $invoice->fecha_emision,
                    'monto'      => $totals['importe_total'],
                ]);
            }

            $payload = [
                'infoTributaria' => ['codDoc' => '01', 'estab' => $estab, 'ptoEmi' => $ptoEmi],
                'infoFactura' => [
                    'fechaEmision' => now()->format('Y-m-d'),
                    'dirEstablecimiento' => $company->dir_matriz,
                    'obligadoContabilidad' => $company->obligado_contabilidad ? 'SI' : 'NO',
                    'tipoIdentificacionComprador' => $contact->tipo_identificacion,
                    'razonSocialComprador' => $contact->razon_social, 'identificacionComprador' => $contact->identificacion,
                    'totalSinImpuestos' => number_format($totals['total_sin_impuestos'], 2, '.', ''),
                    'totalDescuento' => number_format($totals['total_descuento'], 2, '.', ''),
                    'totalImpuesto' => array_map(fn ($t) => ['codigo' => $t['codigo'], 'codigoPorcentaje' => $t['codigoPorcentaje'],
                        'baseImponible' => number_format($t['baseImponible'], 2, '.', ''), 'valor' => number_format($t['valor'], 2, '.', '')], $totals['impuestos']),
                    'propina' => '0.00',
                    'importeTotal' => number_format($totals['importe_total'], 2, '.', ''),
                    'moneda' => 'DOLAR',
                    'pagos' => ['formaPago' => self::SRI_FORMA_PAGO[$formaPago], 'total' => number_format($totals['importe_total'], 2, '.', '')],
                ],
                'detalle' => $totals['detalle'],
                'infoAdicional' => ['email' => $contact->email, 'telefono' => $contact->telefono],
            ];
            $this->emitir->execute($invoice, 'factura', $company, $payload);
            $company->increment('secuencial');
            $this->asiento->handle($invoice);

            // Inventario: cada item baja stock (soporta combos)
            foreach ($items as $item) {
                $codigo = trim((string) ($item['codigo_principal'] ?? ''));
                $cant = (float) ($item['cantidad'] ?? 0);
                if ($codigo === '' || $cant <= 0) {
                    continue;
                }
                $product = Product::where('company_id', $company->id)->where('codigo', $codigo)->first();
                if (! $product) {
                    continue;
                }
                if ($product->es_combo) {
                    foreach ($product->components as $c) {
                        $parte = $c->component;
                        if ($parte && $parte->tipo !== 'servicio') {
                            $this->inventario->handle($parte, 'egreso', $cant * (float) $c->cantidad,
                                (float) $parte->costo_promedio, 'Venta combo '.$invoice->numero, $invoice->fecha_emision->toDateString());
                        }
                    }
                } elseif ($product->tipo !== 'servicio') {
                    $this->inventario->handle($product, 'egreso', $cant, (float) $product->costo_promedio, 'Venta '.$invoice->numero,
                        $invoice->fecha_emision->toDateString(), null, $item['series'] ?? [], $invoice->id);
                }
            }

            return $invoice->load('sriDocument');
        });
    }
}
