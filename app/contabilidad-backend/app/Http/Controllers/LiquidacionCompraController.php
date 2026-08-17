<?php

namespace App\Http\Controllers;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Purchase;
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
        $company = Company::find($purchase->company_id);
        $contact = Contact::find($purchase->contact_id);

        $payload = [
            'infoTributaria' => ['codDoc' => '03'],
            'infoLiquidacionCompra' => [
                'fechaEmision' => now()->format('Y-m-d'),
                'dirEstablecimiento' => $company->dir_matriz,
                'obligadoContabilidad' => $company->obligado_contabilidad === 'SI' ? 'SI' : 'NO',
                'tipoIdentificacionProveedor' => $contact->tipo_identificacion ?? '05',
                'razonSocialProveedor' => $contact->razon_social,
                'identificacionProveedor' => $contact->identificacion,
                'direccionProveedor' => $contact->direccion ?? '',
                'totalSinImpuestos' => number_format($purchase->total_sin_impuestos, 2, '.', ''),
                'totalDescuento' => '0.00',
                'totalConImpuestos' => $this->buildTotalConImpuestos($purchase),
                'propina' => '0.00',
                'importeTotal' => number_format($purchase->importe_total, 2, '.', ''),
                'moneda' => 'DOLAR',
                'pagos' => [
                    'formaPago' => '01',
                    'total' => number_format($purchase->importe_total, 2, '.', ''),
                ],
            ],
            'detalle' => $this->buildDetalle($purchase),
            'infoAdicional' => [
                'email' => $contact->email ?? '',
                'telefono' => $contact->telefono ?? '',
            ],
        ];

        $sriDoc = app(EmitirSriDocument::class)->execute($purchase, 'liquidacionCompra', $company, $payload);
        $company->increment('secuencial');

        return response()->json([
            'ok' => true,
            'sri_document' => $sriDoc,
            'mensaje' => 'Liquidación emitida. Clave: ' . $sriDoc->clave_acceso,
        ]);
    }

    private function buildTotalConImpuestos(Purchase $purchase): array
    {
        $items = $purchase->items ?? [];
        $impuestos = [];
        foreach ($items as $item) {
            $tarifa = $item['tarifa'] ?? 15;
            $base = $item['base_imponible'] ?? ($item['precio_unitario'] * $item['cantidad']);
            $valor = round($base * $tarifa / 100, 2);
            $key = "2-" . ($tarifa == 0 ? '0' : ($tarifa == 5 ? '2' : ($tarifa == 15 ? '4' : '7')));
            if (!isset($impuestos[$key])) {
                $impuestos[$key] = [
                    'codigo' => '2',
                    'codigoPorcentaje' => $tarifa == 0 ? '0' : ($tarifa == 5 ? '2' : ($tarifa == 15 ? '4' : '7')),
                    'baseImponible' => 0,
                    'valor' => 0,
                ];
            }
            $impuestos[$key]['baseImponible'] += $base;
            $impuestos[$key]['valor'] += $valor;
        }
        return array_values(array_map(fn($v) => [
            'codigo' => $v['codigo'],
            'codigoPorcentaje' => $v['codigoPorcentaje'],
            'baseImponible' => number_format($v['baseImponible'], 2, '.', ''),
            'valor' => number_format($v['valor'], 2, '.', ''),
        ], $impuestos));
    }

    private function buildDetalle(Purchase $purchase): array
    {
        $items = $purchase->items ?? [];
        return array_map(function ($item) {
            $base = $item['base_imponible'] ?? ($item['precio_unitario'] * $item['cantidad']);
            $tarifa = $item['tarifa'] ?? 15;
            return [
                'codigoPrincipal' => $item['codigo_principal'] ?? '',
                'descripcion' => $item['descripcion'] ?? '',
                'cantidad' => number_format($item['cantidad'], 2, '.', ''),
                'precioUnitario' => number_format($item['precio_unitario'], 2, '.', ''),
                'descuento' => '0.00',
                'precioTotalSinImpuesto' => number_format($base, 2, '.', ''),
                'impuesto' => [
                    'codigo' => '2',
                    'codigoPorcentaje' => $tarifa == 0 ? '0' : ($tarifa == 5 ? '2' : ($tarifa == 15 ? '4' : '7')),
                    'tarifa' => (string)$tarifa,
                    'baseImponible' => number_format($base, 2, '.', ''),
                    'valor' => number_format(round($base * $tarifa / 100, 2), 2, '.', ''),
                ],
            ];
        }, $items);
    }
}
