<?php
namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Withholding;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Formulario 103 — Retenciones en la fuente del Impuesto a la Renta.
 * Declaración mensual: agrupa por código del SRI la base y el valor retenido.
 */
class Form103Controller extends Controller
{
    public function catalogo()
    {
        return config('retenciones');
    }

    public function formulario(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'anio'       => ['required', 'integer', 'min:2020', 'max:2035'],
            'mes'        => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $desde = Carbon::create($d['anio'], $d['mes'], 1)->startOfMonth();
        $hasta = (clone $desde)->endOfMonth();

        $retenciones = Withholding::where('company_id', $d['company_id'])
            ->where('tipo', 'emitida')
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();

        $catalogo = collect(config('retenciones.renta'))->keyBy('codigo');
        $filas = [];

        foreach ($retenciones as $w) {
            $cod = $w->codigo_retencion ?: $this->deducirCodigo($w);
            if (! $cod) continue;

            $filas[$cod] ??= [
                'codigo'         => $cod,
                'concepto'       => $catalogo[$cod]['nombre'] ?? 'Otras retenciones',
                'porcentaje'     => $catalogo[$cod]['porcentaje'] ?? null,
                'base_imponible' => 0,
                'valor_retenido' => 0,
                'comprobantes'   => 0,
            ];
            $filas[$cod]['base_imponible'] += (float) ($w->base_imponible ?? 0);
            $filas[$cod]['valor_retenido'] += (float) $w->total_retenido;
            $filas[$cod]['comprobantes']++;
        }

        ksort($filas);
        $filas = array_map(function ($f) {
            $f['base_imponible'] = round($f['base_imponible'], 2);
            $f['valor_retenido'] = round($f['valor_retenido'], 2);
            return $f;
        }, array_values($filas));

        $company = Company::find($d['company_id']);

        return [
            'formulario' => '103',
            'periodo'    => ['anio' => $d['anio'], 'mes' => $d['mes'],
                             'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
            'empresa'    => ['ruc' => $company->ruc, 'razon_social' => $company->razon_social],
            'detalle'    => $filas,
            'totales'    => [
                'base_imponible' => round(array_sum(array_column($filas, 'base_imponible')), 2),
                'valor_retenido' => round(array_sum(array_column($filas, 'valor_retenido')), 2),
                'comprobantes'   => array_sum(array_column($filas, 'comprobantes')),
            ],
            // Casilleros que se transcriben al formulario del SRI
            'casilleros' => [
                '499' => round(array_sum(array_column($filas, 'base_imponible')), 2),
                '799' => round(array_sum(array_column($filas, 'valor_retenido')), 2),
                '902' => round(array_sum(array_column($filas, 'valor_retenido')), 2),
            ],
        ];
    }

    /** Retenciones viejas sin código: se deduce por el porcentaje aplicado. */
    private function deducirCodigo(Withholding $w): ?string
    {
        $extra = json_decode((string) $w->xml, true) ?: [];
        if (($extra['tipo'] ?? null) === 'iva') return null;      // el IVA va al 104

        $pct = (float) ($w->porcentaje ?? $extra['porcentaje'] ?? 0);
        foreach (config('retenciones.renta') as $c) {
            if ($c['porcentaje'] > 0 && abs($c['porcentaje'] - $pct) < 0.001) return $c['codigo'];
        }

        return '340';   // otras retenciones
    }
}
