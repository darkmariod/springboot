<?php

namespace App\Http\Controllers;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\SriDocument;
use App\Support\ComprobanteSri;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Guía de remisión (codDoc 06).
 * Guardar crea un borrador (un SriDocument sin XML) que reserva el número y conserva los datos de la guía en `empresa_data.guia`;
 * emitir genera el comprobante del SRI con ESE número y con esos datos, y deja el borrador marcado como "emitida" para que no
 * se emita dos veces. (Mientras no exista una tabla propia de guías, el borrador cuelga de la empresa.)
 */
class GuiaRemisionController extends Controller
{
    private const REGLAS = [
        'transportista_ruc' => 'required|string|size:13',
        'transportista_nombre' => 'required|string',
        'transportista_placa' => 'required|string',
        'fecha_ini_transporte' => 'required|date',
        'fecha_fin_transporte' => 'required|date',
        'dir_partida' => 'required|string',
        'destinatarios' => 'required|array|min:1',
        'destinatarios.*.identificacion' => 'required|string',
        'destinatarios.*.razon_social' => 'required|string',
        'destinatarios.*.direccion' => 'required|string',
        'destinatarios.*.motivo_traslado' => 'required|string',
        'destinatarios.*.detalles' => 'required|array|min:1',
        'destinatarios.*.detalles.*.descripcion' => 'required|string',
        'destinatarios.*.detalles.*.cantidad' => 'required|numeric|min:0.01',
    ];

    public function index(Request $r)
    {
        $companyId = $r->input('company_id');
        $items = SriDocument::where('company_id', $companyId)
            ->where('tipo_comprobante', 'guiaRemision')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        return response()->json($items);
    }

    public function store(Request $r)
    {
        $r->validate(['company_id' => 'required|exists:companies,id'] + self::REGLAS);

        $company = Company::findOrFail($r->input('company_id'));
        $datos = $r->only(array_keys(array_filter(self::REGLAS, fn ($v, $k) => ! str_contains($k, '.'), ARRAY_FILTER_USE_BOTH)));

        $guia = DB::transaction(function () use ($datos, $company) {
            $numero = sprintf('%s-%s-%09d', $company->estab, $company->pto_emi, $company->secuencial);
            $doc = SriDocument::create([
                'company_id' => $company->id,
                // Todavía no hay documento del que dependa: el borrador cuelga de la empresa
                'documentable_type' => $company->getMorphClass(),
                'documentable_id' => $company->id,
                'tipo_comprobante' => 'guiaRemision',
                'estado' => 'borrador',
                'numero_autorizacion' => $numero,
                'empresa_data' => ['guia' => $datos],
                'fecha_emision' => now(),
            ]);
            $company->increment('secuencial');
            return $doc;
        });

        return response()->json(['ok' => true, 'guia' => $guia], 201);
    }

    public function emit(SriDocument $guia)
    {
        if ($guia->tipo_comprobante !== 'guiaRemision') {
            return response()->json(['message' => 'Este comprobante no es una guía de remisión.'], 422);
        }
        $yaEmitida = $guia->estado !== 'borrador'
            || SriDocument::where('documentable_type', $guia->getMorphClass())->where('documentable_id', $guia->id)->exists();
        if ($yaEmitida) {
            return response()->json(['message' => 'Esta guía de remisión ya fue emitida al SRI.'], 422);
        }

        $company = Company::find($guia->company_id);
        // Los datos guardados con la guía mandan; si es un borrador de la versión anterior, se leen de la solicitud
        $datos = $guia->empresa_data['guia'] ?? request()->all();
        $validador = Validator::make($datos, self::REGLAS);
        if ($validador->fails()) {
            return response()->json(['message' => $validador->errors()->first(), 'errors' => $validador->errors()], 422);
        }

        $destinatarios = collect($datos['destinatarios'])->map(fn ($d) => [
            'identificacionDestinatario' => $d['identificacion'],
            'razonSocialDestinatario' => $d['razon_social'],
            'dirDestinatario' => $d['direccion'],
            'motivoTraslado' => $d['motivo_traslado'],
            'codDocSustento' => $d['cod_doc_sustento'] ?? '01',
            'numDocSustento' => $d['num_doc_sustento'] ?? '',
            'numAutDocSustento' => $d['num_aut_doc_sustento'] ?? '',
            'fechaEmisionDocSustento' => $d['fecha_emision_doc_sustento'] ?? now()->format('Y-m-d'),
            'detalles' => collect($d['detalles'])->map(fn ($det) => [
                'codigoInterno' => $det['codigo_interno'] ?? $det['codigo_principal'] ?? '',
                'descripcion' => $det['descripcion'],
                'cantidad' => number_format((float) $det['cantidad'], 2, '.', ''),
            ])->all(),
        ])->all();

        $payload = [
            // Guardar la guía ya le reservó su número: la clave de acceso lleva ese mismo secuencial
            'infoTributaria' => ComprobanteSri::infoTributaria('06', $guia->numero_autorizacion),
            'infoGuiaRemision' => [
                // La guía no imprime su fecha de emisión en el XML, pero la clave de acceso la lleva y la librería la lee de aquí
                'fechaEmision' => now()->format('Y-m-d'),
                'dirEstablecimiento' => $company->dir_matriz,
                'dirPartida' => $datos['dir_partida'] ?: $company->dir_matriz,
                'razonSocialTransportista' => $datos['transportista_nombre'],
                'tipoIdentificacionTransportista' => '04',
                'rucTransportista' => $datos['transportista_ruc'],
                'obligadoContabilidad' => $company->obligado_contabilidad ? 'SI' : 'NO',
                'fechaIniTransporte' => $datos['fecha_ini_transporte'],
                'fechaFinTransporte' => $datos['fecha_fin_transporte'],
                'placa' => $datos['transportista_placa'],
            ],
            'destinatarios' => $destinatarios,
            'infoAdicional' => ComprobanteSri::infoAdicional(null, null),
        ];

        $sriDoc = app(EmitirSriDocument::class)->execute($guia, 'guiaRemision', $company, $payload);
        $guia->update(['estado' => 'emitida']);
        // Un borrador de la versión anterior (sin número en el formato del SRI) toma el siguiente de la empresa
        if (! ComprobanteSri::reservado($guia->numero_autorizacion)) {
            $guia->update(['numero_autorizacion' => sprintf('%s-%s-%09d', $company->estab, $company->pto_emi, $company->secuencial)]);
            $company->increment('secuencial');
        }

        return response()->json([
            'ok' => true,
            'sri_document' => $sriDoc,
            'mensaje' => 'Guía de remisión emitida. Clave: ' . $sriDoc->clave_acceso,
        ]);
    }
}
