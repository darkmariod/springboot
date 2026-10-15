<?php

namespace App\Http\Controllers;

use App\Support\PeriodosContables;
use Illuminate\Http\Request;

/**
 * Períodos contables y cierre (T4.3a). La lógica vive en App\Support\PeriodosContables; aquí se validan los datos y se responde.
 *
 *   GET  accounting/periodos?company_id&anio          los 12 meses con su estado y sus asientos (administrador y contador)
 *   POST accounting/periodos/cerrar                   cierra un mes: mayoriza lo pendiente (administrador y contador)
 *   POST accounting/periodos/reabrir                  reabre un mes cerrado, con motivo (solo administrador, lo exige la ruta)
 *   POST accounting/cierre-ejercicio                  asiento de cierre del 31/12 (administrador y contador)
 *
 * Un mes sin registro está abierto. El cierre y la reapertura quedan en la auditoría.
 */
class PeriodoContableController extends Controller
{
    public function index(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'anio' => ['nullable', 'integer', 'between:2000,2100'],
        ], $this->mensajes());

        return PeriodosContables::resumen((int) $d['company_id'], (int) ($d['anio'] ?? now()->year));
    }

    public function cerrar(Request $r)
    {
        $d = $this->validarMes($r);
        $res = PeriodosContables::cerrar($d['company_id'], $d['anio'], $d['mes'], $r->user()?->id);

        return ['ok' => true] + $res;
    }

    public function reabrir(Request $r)
    {
        $d = $this->validarMes($r, ['motivo' => ['required', 'string', 'min:3', 'max:255']]);
        $periodo = PeriodosContables::reabrir($d['company_id'], $d['anio'], $d['mes'], trim($d['motivo']), $r->user()?->id);

        return ['ok' => true, 'periodo' => $periodo];
    }

    public function cerrarEjercicio(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'anio' => ['required', 'integer', 'between:2000,2100'],
        ], $this->mensajes());

        return ['ok' => true] + PeriodosContables::cerrarEjercicio((int) $d['company_id'], (int) $d['anio'], $r->user()?->id);
    }

    /** @return array{company_id:int,anio:int,mes:int,motivo?:string} */
    private function validarMes(Request $r, array $extra = []): array
    {
        $d = $r->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'anio' => ['required', 'integer', 'between:2000,2100'],
            'mes' => ['required', 'integer', 'between:1,12'],
        ] + $extra, $this->mensajes());

        return ['company_id' => (int) $d['company_id'], 'anio' => (int) $d['anio'], 'mes' => (int) $d['mes']] + array_intersect_key($d, ['motivo' => 1]);
    }

    /** @return array<string,string> */
    private function mensajes(): array
    {
        return [
            'company_id.required' => 'Falta la empresa.', 'company_id.exists' => 'La empresa no existe.',
            'anio.required' => 'Indica el año.', 'anio.integer' => 'El año no es válido.', 'anio.between' => 'El año no es válido.',
            'mes.required' => 'Indica el mes.', 'mes.integer' => 'El mes no es válido.', 'mes.between' => 'El mes debe estar entre 1 y 12.',
            'motivo.required' => 'Escribe el motivo de la reapertura.', 'motivo.min' => 'El motivo de la reapertura es muy corto.',
            'motivo.max' => 'El motivo no puede pasar de 255 caracteres.',
        ];
    }
}
