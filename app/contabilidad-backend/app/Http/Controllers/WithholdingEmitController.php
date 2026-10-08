<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Withholding;
use App\Services\EmitirRetencion;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Retenciones que la empresa emite a sus proveedores (comprobante 07).
 * Registrar crea las filas, el asiento y baja el saldo de la compra; el comprobante electrónico
 * se genera con el mismo mecanismo de los demás documentos del SRI (ver EmitirRetencion).
 */
class WithholdingEmitController extends Controller
{
    public function index(Request $r)
    {
        return Withholding::with('invoice:id,numero', 'purchase:id,numero', 'sriDocument:id,documentable_id,documentable_type,estado,clave_acceso')
            ->where('tipo', 'emitida')
            ->when($r->company_id, fn($q, $id) => $q->where('company_id', $id))
            ->latest()->get();
    }

    /**
     * Registra la retención de una compra.
     *
     * Forma nueva: `lineas` = [{tipo: renta|iva, codigo, base_imponible, porcentaje?}, ...].
     * Forma anterior (una sola línea): `tipo`, `porcentaje`, `base_imponible`.
     */
    public function store(Request $r, EmitirRetencion $emitir)
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'purchase_id' => ['required', 'exists:purchases,id'],
            'lineas' => ['sometimes', 'array', 'min:1', 'max:20'],
            'lineas.*.tipo' => ['required', 'in:iva,renta'],
            'lineas.*.codigo' => ['required'],
            'lineas.*.base_imponible' => ['required', 'numeric', 'gt:0'],
            'lineas.*.porcentaje' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'tipo' => ['required_without:lineas', 'in:iva,renta'],
            'porcentaje' => ['required_without:lineas', 'numeric', 'min:0.01', 'max:100'],
            'base_imponible' => ['required_without:lineas', 'numeric', 'min:0.01'],
            'numero_comprobante' => ['nullable', 'string'],
        ], [
            'purchase_id.required' => 'Elige la compra que se retiene.',
            'purchase_id.exists' => 'La compra no existe.',
            'lineas.min' => 'Agrega al menos una línea de retención.',
            'lineas.max' => 'Una retención admite hasta 20 líneas.',
            'lineas.*.tipo.required' => 'Elige el tipo de retención (renta o IVA).',
            'lineas.*.tipo.in' => 'El tipo de retención debe ser renta o IVA.',
            'lineas.*.codigo.required' => 'Elige el código de retención.',
            'lineas.*.base_imponible.required' => 'Indica la base imponible.',
            'lineas.*.base_imponible.numeric' => 'La base imponible debe ser un número.',
            'lineas.*.base_imponible.gt' => 'La base imponible debe ser mayor a cero.',
            'lineas.*.porcentaje.numeric' => 'El porcentaje debe ser un número.',
            'lineas.*.porcentaje.gt' => 'El porcentaje debe ser mayor a cero.',
            'lineas.*.porcentaje.max' => 'El porcentaje no puede pasar de 100.',
            'tipo.required_without' => 'Elige el tipo de retención (renta o IVA).',
            'tipo.in' => 'El tipo de retención debe ser renta o IVA.',
            'porcentaje.required_without' => 'Indica el porcentaje de retención.',
            'base_imponible.required_without' => 'Indica la base imponible.',
        ]);

        $compra = Purchase::findOrFail($d['purchase_id']);
        if ($compra->company_id !== (int) $d['company_id']) {
            throw ValidationException::withMessages(['purchase_id' => ['La compra no pertenece a esta empresa.']]);
        }

        $lineas = $d['lineas'] ?? [[
            'tipo' => $d['tipo'], 'porcentaje' => $d['porcentaje'], 'base_imponible' => $d['base_imponible'],
        ]];

        $registro = $emitir->registrar($compra, $lineas, $d['numero_comprobante'] ?? null);
        $principal = $registro['filas']->first();

        // El comprobante electrónico va después de guardar: si falla, la retención ya está registrada
        $emision = $registro['electronico']
            ? $emitir->generarDocumento($principal)
            : ['sri_document' => null, 'estado' => 'manual', 'mensaje' => 'Retención registrada con el número indicado; no genera comprobante electrónico.'];

        return response()->json([
            'ok' => true,
            'id' => $principal->id,
            'numero' => $registro['numero'],
            'fecha' => $registro['fecha'],
            'total_retenido' => $registro['total'],
            'saldo_pendiente' => (float) $registro['compra']->saldo_pendiente,
            'retenciones' => $registro['filas']->map(fn (Withholding $w) => $this->linea($w, $emitir))->values(),
            'sri_document' => $this->documento($emision['sri_document']),
            'emision' => ['estado' => $emision['estado'], 'mensaje' => $emision['mensaje']],
        ], 201);
    }

    /** Reintenta el comprobante electrónico de una retención ya registrada (no vuelve a asentar nada). */
    public function emit(Withholding $withholding, EmitirRetencion $emitir)
    {
        if ($withholding->tipo !== 'emitida') {
            return response()->json(['message' => 'Solo se pueden emitir retenciones propias, no las recibidas.'], 422);
        }

        $emision = $emitir->generarDocumento($withholding);

        return response()->json([
            'ok' => in_array($emision['estado'], ['error', 'no_generado'], true) === false,
            'numero' => $withholding->numero,
            'sri_document' => $this->documento($emision['sri_document']),
            'emision' => ['estado' => $emision['estado'], 'mensaje' => $emision['mensaje']],
        ]);
    }

    /** Retenciones de una compra, agrupadas por comprobante, con lo retenido y el saldo que queda. */
    public function porCompra(Purchase $purchase, EmitirRetencion $emitir)
    {
        $filas = Withholding::with('sriDocument:id,documentable_id,documentable_type,estado,clave_acceso')
            ->where('tipo', 'emitida')->where('purchase_id', $purchase->id)
            ->orderBy('id')->get();

        $comprobantes = $filas->groupBy('numero')->map(function ($grupo, $numero) use ($emitir) {
            $principal = $grupo->first();
            $doc = $principal->sriDocument;

            return [
                'id' => $principal->id,
                'numero' => (string) $numero,
                'fecha' => $principal->fecha,
                'total_retenido' => round((float) $grupo->sum('total_retenido'), 2),
                'estado_sri' => $doc?->estado,
                'clave_acceso' => $doc?->clave_acceso,
                'lineas' => $grupo->map(fn (Withholding $w) => $this->linea($w, $emitir))->values(),
            ];
        })->values();

        return [
            'importe_total' => (float) $purchase->importe_total,
            'saldo_pendiente' => (float) $purchase->saldo_pendiente,
            'total_retenido' => round((float) $filas->sum('total_retenido'), 2),
            'comprobantes' => $comprobantes,
        ];
    }

    private function linea(Withholding $w, EmitirRetencion $emitir): array
    {
        $extra = $emitir->extra($w);

        return [
            'id' => $w->id,
            'tipo' => $extra['tipo'] ?? null,
            'codigo' => $extra['codigo'] ?? $w->codigo_retencion,
            'base_imponible' => (float) $w->base_imponible,
            'porcentaje' => (float) $w->porcentaje,
            'valor_retenido' => (float) $w->total_retenido,
        ];
    }

    private function documento($doc): ?array
    {
        return $doc ? [
            'id' => $doc->id, 'tipo_comprobante' => $doc->tipo_comprobante,
            'estado' => $doc->estado, 'clave_acceso' => $doc->clave_acceso,
        ] : null;
    }
}
