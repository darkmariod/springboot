<?php

namespace App\Http\Controllers;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuiaRemisionController extends Controller
{
    public function index(Request $r)
    {
        $companyId = $r->input('company_id');
        $items = \App\Models\SriDocument::where('company_id', $companyId)
            ->where('tipo_comprobante', 'guiaRemision')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        return response()->json($items);
    }

    public function store(Request $r)
    {
        $r->validate([
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
        ]);

        $company = Company::findOrFail($r->input('company_id'));

        $guia = DB::transaction(function () use ($r, $company) {
            $numero = sprintf('%s-%s-%09d', $company->estab, $company->pto_emi, $company->secuencial);
            $doc = \App\Models\SriDocument::create([
                'company_id' => $company->id,
                'tipo_comprobante' => 'guiaRemision',
                'estado' => 'borrador',
                'numero_autorizacion' => $numero,
                'fecha_emision' => now(),
            ]);
            $company->increment('secuencial');
            return $doc;
        });

        return response()->json(['ok' => true, 'guia' => $guia], 201);
    }

    public function emit(\App\Models\SriDocument $guia)
    {
        $company = Company::find($guia->company_id);
        $r = request();

        $destinatarios = collect($r->input('destinatarios', []))->map(fn($d) => [
            'identificacionDestinatario' => $d['identificacion'],
            'razonSocialDestinatario' => $d['razon_social'],
            'dirDestinatario' => $d['direccion'],
            'motivoTraslado' => $d['motivo_traslado'],
            'codDocSustento' => $d['cod_doc_sustento'] ?? '01',
            'numDocSustento' => $d['num_doc_sustento'] ?? '',
            'numAutDocSustento' => $d['num_aut_doc_sustento'] ?? '',
            'fechaEmisionDocSustento' => $d['fecha_emision_doc_sustento'] ?? now()->format('Y-m-d'),
            'detalles' => collect($d['detalles'] ?? [])->map(fn($det) => [
                'codigoInterno' => $det['codigo_interno'] ?? $det['codigo_principal'] ?? '',
                'descripcion' => $det['descripcion'] ?? '',
                'cantidad' => number_format($det['cantidad'], 2, '.', ''),
            ])->all(),
        ])->all();

        $payload = [
            'infoTributaria' => ['codDoc' => '06'],
            'infoGuiaRemision' => [
                'dirEstablecimiento' => $company->dir_matriz,
                'dirPartida' => $r->input('dir_partida', $company->dir_matriz),
                'razonSocialTransportista' => $r->input('transportista_nombre', ''),
                'tipoIdentificacionTransportista' => '04',
                'rucTransportista' => $r->input('transportista_ruc', ''),
                'obligadoContabilidad' => $company->obligado_contabilidad === 'SI' ? 'SI' : 'NO',
                'fechaIniTransporte' => $r->input('fecha_ini_transporte', now()->format('Y-m-d')),
                'fechaFinTransporte' => $r->input('fecha_fin_transporte', now()->format('Y-m-d')),
                'placa' => $r->input('transportista_placa', ''),
            ],
            'destinatarios' => $destinatarios,
            'infoAdicional' => [],
        ];

        $sriDoc = app(EmitirSriDocument::class)->execute($guia, 'guiaRemision', $company, $payload);

        return response()->json([
            'ok' => true,
            'sri_document' => $sriDoc,
            'mensaje' => 'Guía de remisión emitida. Clave: ' . $sriDoc->clave_acceso,
        ]);
    }
}
