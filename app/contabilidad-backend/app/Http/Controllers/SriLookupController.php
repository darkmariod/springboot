<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Autocompletar datos del cliente por cédula/RUC (pedido estrella de Javier).
 *
 * RUC → servicio PÚBLICO del SRI (gratis): razón social real.
 * Cédula persona natural sin RUC → el nombre sale del Registro Civil, que NO es público.
 *   Se resuelve con un proveedor pago ("API comprada") cuando se configure SRI_CEDULA_PROVIDER.
 *   Sin eso, se responde requiere_carga_manual y el usuario tipea el nombre (no rompe el flujo).
 */
class SriLookupController extends Controller
{
    private const SRI_RUC_URL =
        'https://srienlinea.sri.gob.ec/sri-catastro-sujeto-servicio-internet/rest/ConsolidadoContribuyente/obtenerPorNumerosRuc';

    public function consulta(Request $request)
    {
        $id = preg_replace('/\D/', '', (string) $request->query('identificacion', ''));

        if (strlen($id) !== 10 && strlen($id) !== 13) {
            return response()->json([
                'encontrado' => false,
                'error' => 'La identificación debe tener 10 dígitos (cédula) o 13 (RUC).',
            ], 422);
        }

        // Cédula → RUC de persona natural = cédula + 001 (así lo maneja el SRI).
        $ruc = strlen($id) === 13 ? $id : $id . '001';

        try {
            $res = Http::acceptJson()->timeout(8)->get(self::SRI_RUC_URL, ['ruc' => $ruc]);

            if ($res->ok() && is_array($res->json()) && count($res->json()) > 0) {
                $c = $res->json()[0];
                return response()->json([
                    'encontrado' => true,
                    'tipo_identificacion' => strlen($id) === 13 ? '04' : '05', // 04 RUC · 05 cédula
                    'identificacion' => $id,
                    'razon_social' => $c['razonSocial'] ?? null,
                    'nombre_comercial' => $c['nombreComercial'] ?? null,
                    'estado' => $c['estadoContribuyenteRuc'] ?? null,
                    'obligado_contabilidad' => ($c['obligadoLlevarContabilidad'] ?? 'NO') === 'SI',
                ]);
            }
        } catch (Throwable $e) {
            // Sin internet / SRI caído: no rompemos, se carga a mano.
            return response()->json([
                'encontrado' => false,
                'requiere_carga_manual' => true,
                'mensaje' => 'No se pudo consultar el SRI ahora. Cargá los datos a mano.',
            ]);
        }

        // No encontrado en el padrón del SRI (típico en cédula de persona natural sin RUC).
        return response()->json([
            'encontrado' => false,
            'requiere_carga_manual' => true,
            'mensaje' => 'No está en el padrón del SRI. Verificá el número o cargá los datos a mano.',
        ]);
    }
}
