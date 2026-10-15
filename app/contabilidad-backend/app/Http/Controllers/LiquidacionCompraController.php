<?php

namespace App\Http\Controllers;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Services\DocumentCalculator;
use App\Support\ComprobanteSri;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LiquidacionCompraController extends Controller
{
    public function index(Request $r)
    {
        $companyId = $r->input('company_id');
        $items = Purchase::with('contact')
            ->where('company_id', $companyId)
            ->where('tipo_comprobante', 'liquidacion_compra')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        return response()->json($items);
    }

    public function store(Request $r)
    {
        $r->validate([
            'contact_id' => 'required|exists:contacts,id',
            'items' => 'required|array|min:1',
            'importe_total' => 'required|numeric|min:0',
            'forma_pago' => 'required|string',
        ]);

        $company = Company::findOrFail($r->input('company_id'));

        $purchase = DB::transaction(function () use ($r, $company) {
            $numero = sprintf('%s-%s-%09d', $company->estab, $company->pto_emi, $company->secuencial);
            $p = Purchase::create([
                'company_id' => $company->id,
                'contact_id' => $r->contact_id,
                'numero' => $numero,
                'fecha_emision' => now()->toDateString(),
                'items' => $r->items,
                'total_sin_impuestos' => $r->total_sin_impuestos ?? $r->importe_total,
                'total_impuesto' => $r->total_impuesto ?? 0,
                'importe_total' => $r->importe_total,
                'saldo_pendiente' => $r->importe_total,
                'tipo_comprobante' => 'liquidacion_compra',
            ]);
            $company->increment('secuencial');
            return $p;
        });

        return response()->json(['ok' => true, 'purchase' => $purchase], 201);
    }

    public function emit(Purchase $purchase)
    {
        if ($purchase->tipo_comprobante !== 'liquidacion_compra') {
            return response()->json(['message' => 'Este comprobante no es una liquidación de compra.'], 422);
        }
        if (SriDocument::where('documentable_type', $purchase->getMorphClass())->where('documentable_id', $purchase->id)->exists()) {
            return response()->json(['message' => 'Esta liquidación de compra ya fue emitida al SRI.'], 422);
        }

        $company = Company::find($purchase->company_id);
        $contact = Contact::find($purchase->contact_id);
        $calculo = app(DocumentCalculator::class)->fromItems($this->lineas($purchase));

        $payload = [
            // Guardar la liquidación ya le reservó su número: la clave de acceso lleva ese mismo secuencial
            'infoTributaria' => ComprobanteSri::infoTributaria('03', $purchase->numero),
            'infoLiquidacionCompra' => [
                'fechaEmision' => now()->format('Y-m-d'),
                'dirEstablecimiento' => $company->dir_matriz,
                'obligadoContabilidad' => $company->obligado_contabilidad ? 'SI' : 'NO',
                'tipoIdentificacionProveedor' => $contact->tipo_identificacion ?? '05',
                'razonSocialProveedor' => $contact->razon_social,
                'identificacionProveedor' => $contact->identificacion,
                'direccionProveedor' => $contact->direccion ?? '',
                'totalSinImpuestos' => number_format($purchase->total_sin_impuestos, 2, '.', ''),
                'totalDescuento' => '0.00',
                'totalConImpuestos' => array_map(fn ($t) => [
                    'codigo' => $t['codigo'], 'codigoPorcentaje' => $t['codigoPorcentaje'],
                    'baseImponible' => number_format($t['baseImponible'], 2, '.', ''), 'valor' => number_format($t['valor'], 2, '.', ''),
                ], $calculo['impuestos']),
                'propina' => '0.00',
                'importeTotal' => number_format($purchase->importe_total, 2, '.', ''),
                'moneda' => 'DOLAR',
                'pagos' => [
                    'formaPago' => '01',
                    'total' => number_format($purchase->importe_total, 2, '.', ''),
                ],
            ],
            'detalle' => $calculo['detalle'],
            'infoAdicional' => ComprobanteSri::infoAdicional($contact->email ?? null, $contact->telefono ?? null),
        ];

        $sriDoc = app(EmitirSriDocument::class)->execute($purchase, 'liquidacionCompra', $company, $payload);
        // Una liquidación guardada con la versión anterior (sin número en el formato del SRI) toma el siguiente de la empresa
        if (! ComprobanteSri::reservado($purchase->numero)) {
            $purchase->update(['numero' => sprintf('%s-%s-%09d', $company->estab, $company->pto_emi, $company->secuencial)]);
            $company->increment('secuencial');
        }

        return response()->json([
            'ok' => true,
            'sri_document' => $sriDoc,
            'mensaje' => 'Liquidación emitida. Clave: ' . $sriDoc->clave_acceso,
        ]);
    }

    /**
     * Las líneas de la liquidación en el formato de DocumentCalculator (el mismo del que sale el detalle de una factura):
     * el código de porcentaje del IVA sale de la tarifa (15% = 4, 5% = 5, 0% = 0) y lo que la línea trae como base
     * imponible menos que cantidad × precio es el descuento.
     */
    private function lineas(Purchase $purchase): array
    {
        return array_map(function ($item) {
            $cantidad = (float) ($item['cantidad'] ?? 1);
            $precio = (float) ($item['precio_unitario'] ?? 0);
            $bruto = round($cantidad * $precio, 2);
            $base = isset($item['base_imponible']) ? round((float) $item['base_imponible'], 2) : $bruto;

            return [
                'codigo_principal' => (string) ($item['codigo_principal'] ?? ''),
                'descripcion' => (string) ($item['descripcion'] ?? ''),
                'cantidad' => $cantidad,
                'precio_unitario' => $precio,
                'descuento' => max(0.0, round($bruto - $base, 2)),
                'tarifa' => $item['tarifa'] ?? 15,
            ];
        }, array_values($purchase->items ?? []));
    }
}
