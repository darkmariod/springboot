<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Sri\ClaveAccesoProvider;
use App\Services\Sri\SriImportProvider;
use App\Services\Sri\XmlUploadProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Manager;

/**
 * POST /sri/importar — importa comprobantes SRI vía provider pluggable.
 *
 * Estrategias soportadas:
 *   - clave_acceso: descarga XML del SRI por clave de acceso (49 dígitos)
 *   - xml_upload:   procesa un XML suelto subido por el usuario
 *   - api_paga:     (futuro) consulta la API de Paga
 */
class SriImportController extends Controller
{
    /** Mapa de strategies → provider classes */
    private const PROVIDERS = [
        'clave_acceso' => ClaveAccesoProvider::class,
        'xml_upload'   => XmlUploadProvider::class,
    ];

    public function importar(Request $r)
    {
        $r->validate([
            'company_id'   => ['required', 'exists:companies,id'],
            'strategy'     => ['required', 'in:clave_acceso,xml_upload,api_paga'],
            'clave_acceso' => ['required_if:strategy,clave_acceso', 'nullable', 'string', 'size:49'],
            'xml_file'     => ['required_if:strategy,xml_upload', 'nullable', 'file', 'mimes:xml', 'max:10240'],
        ]);

        $strategy = $r->input('strategy');

        if (!isset(self::PROVIDERS[$strategy])) {
            return response()->json([
                'message' => "La estrategia '{$strategy}' no está implementada todavía.",
            ], 422);
        }

        $company = Company::findOrFail($r->input('company_id'));
        $provider = app(self::PROVIDERS[$strategy]);

        try {
            $purchase = $provider->handle($company, $r->all());
            return response()->json([
                'ok'       => true,
                'purchase' => $purchase->load('contact:id,razon_social'),
                'strategy' => $strategy,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message'  => 'Error al importar: ' . $e->getMessage(),
                'strategy' => $strategy,
            ], 422);
        }
    }
}
