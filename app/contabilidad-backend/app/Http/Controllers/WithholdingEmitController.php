<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Purchase;
use App\Models\Withholding;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Emisión de comprobantes de retención al proveedor (codDoc 07).
 * Cuando la empresa retiene IVA o Renta a un proveedor, emite este comprobante.
 */
class WithholdingEmitController extends Controller
{
    public function index(Request $r)
    {
        return Withholding::with('invoice:id,numero')
            ->where('tipo', 'emitida')
            ->when($r->company_id, fn($q, $id) => $q->where('company_id', $id))
            ->latest()->get();
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'purchase_id' => ['required', 'exists:purchases,id'],
            'tipo' => ['required', 'in:iva,renta'],
            'porcentaje' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'base_imponible' => ['required', 'numeric', 'min:0.01'],
            'numero_comprobante' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($d) {
            $purchase = Purchase::find($d['purchase_id']);
            $company = Company::find($d['company_id']);
            $valorRetenido = round($d['base_imponible'] * $d['porcentaje'] / 100, 2);

            $w = Withholding::create([
                'company_id' => $d['company_id'],
                'invoice_id' => null, // es una retención emitida, no recibida
                'tipo' => 'emitida',
                'numero' => $d['numero_comprobante'] ?? sprintf('%s-%s-%09d', $company->estab, $company->pto_emi, $company->secuencial),
                'clave_acceso' => null,
                'fecha' => now()->toDateString(),
                'total_retenido' => $valorRetenido,
                'xml' => json_encode([
                    'tipo' => $d['tipo'],
                    'porcentaje' => $d['porcentaje'],
                    'base_imponible' => $d['base_imponible'],
                    'purchase_id' => $d['purchase_id'],
                ]),
            ]);

            // Asiento: retención por pagar (pasivo) contra CxP
            $codigoRet = $d['tipo'] === 'iva' ? '2.1.04' : '2.1.05';
            $nombreRet = $d['tipo'] === 'iva' ? 'Retención IVA por pagar' : 'Retención Renta por pagar';

            SimpleEntry::make($d['company_id'], 'Retención emitida ' . $w->numero . ' — ' . strtoupper($d['tipo']), [
                ['codigo' => $codigoRet, 'nombre' => $nombreRet, 'tipo' => 'pasivo',
                    'debe' => 0, 'haber' => $valorRetenido, 'ref' => $w->numero],
                ['codigo' => '2.1.01', 'nombre' => 'Cuentas por pagar proveedores', 'tipo' => 'pasivo',
                    'debe' => $valorRetenido, 'haber' => 0, 'ref' => $w->numero],
            ], $w);

            // Reducir saldo pendiente de la compra
            $purchase->decrement('saldo_pendiente', $valorRetenido);

            $company->increment('secuencial');

            return response()->json($w, 201);
        });
    }
}
