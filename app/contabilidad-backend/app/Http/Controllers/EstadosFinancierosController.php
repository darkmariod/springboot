<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Support\EstadosFinancieros;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Estados financieros por período con el detalle por cuenta (T4.3b). El cálculo vive en App\Support\EstadosFinancieros;
 * aquí se validan los filtros y se entrega en JSON o, con ?formato=excel|pdf, en el mismo patrón de exportación de los demás reportes.
 *
 *   GET accounting/estado-resultados?company_id&desde&hasta   ingresos y gastos del período, en árbol por código de cuenta
 *   GET accounting/balance-general?company_id&hasta           activo, pasivo y patrimonio a esa fecha, con la verificación contable
 *
 * Sin fechas se cuenta todo el historial. Solo administrador y contador (lo exige la ruta).
 */
class EstadosFinancierosController extends Controller
{
    use ExportsReports;

    public function resultados(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'formato' => ['nullable', Rule::in(['excel', 'pdf', 'json'])],
        ], $this->mensajes());

        $res = EstadosFinancieros::estadoResultados((int) $d['company_id'], $d['desde'] ?? null, $d['hasta'] ?? null);
        if (! $this->exporta($r)) {
            return $res;
        }

        $filas = [];
        foreach ($res['secciones'] as $s) {
            $filas[] = mb_strtoupper($s['titulo']);
            array_push($filas, ...EstadosFinancieros::filas($s['cuentas']));
            $filas[] = ['__total' => ['', 'TOTAL '.mb_strtoupper($s['titulo']), EstadosFinancieros::n($s['total'])]];
        }
        $filas[] = ['__total' => ['', 'RESULTADO DEL PERÍODO', EstadosFinancieros::n($res['totales']['resultado'])]];

        $rango = $this->rotulo($res['desde'], $res['hasta']);

        return $this->entregar($r, 'Estado de resultados'.$rango, $filas,
            'estado-resultados'.($res['desde'] ? '-'.$res['desde'] : '').($res['hasta'] ? '-'.$res['hasta'] : ''));
    }

    public function balance(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'hasta' => ['nullable', 'date'],
            'formato' => ['nullable', Rule::in(['excel', 'pdf', 'json'])],
        ], $this->mensajes());

        $res = EstadosFinancieros::balanceGeneral((int) $d['company_id'], $d['hasta'] ?? null);
        if (! $this->exporta($r)) {
            return $res;
        }

        $t = $res['totales'];
        $filas = [];
        foreach ($res['secciones'] as $s) {
            $filas[] = mb_strtoupper($s['titulo']);
            array_push($filas, ...EstadosFinancieros::filas($s['cuentas']));
            $filas[] = ['__total' => ['', 'TOTAL '.mb_strtoupper($s['titulo']), EstadosFinancieros::n($s['total'])]];
        }
        $filas[] = ['', 'Resultado del período', EstadosFinancieros::n($t['resultado_periodo'])];
        $filas[] = ['__total' => ['', 'TOTAL PASIVO + PATRIMONIO + RESULTADO', EstadosFinancieros::n($t['pasivo_patrimonio_resultado'])]];
        $filas[] = ['', 'Verificación', $t['cuadra'] ? 'Cuadra' : 'Diferencia $'.EstadosFinancieros::n($t['diferencia'])];

        return $this->entregar($r, 'Estado de situación financiera'.($res['hasta'] ? ' al '.Carbon::parse($res['hasta'])->format('d/m/Y') : ''), $filas,
            'balance-general'.($res['hasta'] ? '-'.$res['hasta'] : ''));
    }

    /** ¿Piden un archivo? Sin formato (o formato=json) se devuelve el JSON. */
    private function exporta(Request $r): bool
    {
        return in_array($r->input('formato'), ['excel', 'pdf'], true);
    }

    /** Excel = CSV con marca UTF-8 (como los demás reportes); PDF = la misma tabla. */
    private function entregar(Request $r, string $titulo, array $filas, string $archivo)
    {
        $encabezados = ['Código', 'Cuenta', 'Saldo'];
        if ($r->input('formato') === 'pdf') {
            // pdfResponse arma HTML con el texto tal cual: se escapa para que un "&" en un nombre no rompa la tabla
            $limpio = fn (array $fila) => array_map(fn ($c) => htmlspecialchars((string) $c), $fila);
            $filas = array_map(fn ($f) => is_array($f) ? (isset($f['__total']) ? ['__total' => $limpio($f['__total'])] : $limpio($f)) : $f, $filas);

            return $this->pdfResponse($titulo, $encabezados, $filas, $archivo);
        }

        return $this->csvResponse($encabezados, $filas, $archivo, true);
    }

    private function rotulo(?string $desde, ?string $hasta): string
    {
        $f = fn (string $x) => Carbon::parse($x)->format('d/m/Y');
        if ($desde && $hasta) {
            return ' del '.$f($desde).' al '.$f($hasta);
        }

        return $hasta ? ' hasta el '.$f($hasta) : ($desde ? ' desde el '.$f($desde) : '');
    }

    /** @return array<string,string> */
    private function mensajes(): array
    {
        return [
            'company_id.required' => 'Falta la empresa.', 'company_id.exists' => 'La empresa no existe.',
            'desde.date' => 'La fecha desde no es válida.', 'hasta.date' => 'La fecha hasta no es válida.',
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'formato.in' => 'El formato debe ser excel, pdf o json.',
        ];
    }
}
