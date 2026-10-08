<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Models\Contact;
use App\Support\EstadoCuenta;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Estado de cuenta de clientes y de proveedores (T3.1). El cálculo vive en App\Support\EstadoCuenta; aquí solo se
 * validan los filtros, se exige que el contacto sea de la empresa y se entrega en JSON o, con ?formato=excel|pdf,
 * en el mismo patrón de exportación de los reportes de ventas y compras (ExportsReports).
 *
 *   GET estado-cuenta/clientes                 resumen por cliente (con totales y antigüedad)
 *   GET estado-cuenta/clientes/{contact}       cada factura con sus notas, retención y cobros, y los movimientos
 *   GET estado-cuenta/proveedores              resumen por proveedor
 *   GET estado-cuenta/proveedores/{contact}    cada compra con sus retenciones y pagos, y los movimientos
 *
 * Filtros: company_id (obligatorio), desde / hasta (fecha de emisión del documento), buscar (resumen) y
 * con_saldo (resumen: solo quien debe o tiene algo a favor). En el detalle exportado, vista=documentos|movimientos.
 */
class EstadoCuentaController extends Controller
{
    use ExportsReports;

    public function clientes(Request $r)
    {
        $f = $this->filtros($r);
        $data = EstadoCuenta::resumenClientes($f['company_id'], $f['desde'], $f['hasta'], $f['buscar'], $f['con_saldo']);
        if (! $this->exporta($r)) {
            return response()->json($data);
        }

        $t = $data['totales'];
        $headers = ['Cliente', 'Identificación', 'Facturado', 'Notas de débito', 'Notas de crédito', 'Retenciones', 'Cobros', 'Saldo',
            '0-30 días', '31-60 días', '61-90 días', 'Más de 90 días', 'Anticipos sin aplicar', 'Saldo a favor (notas de crédito)', 'Saldo neto'];
        $rows = array_map(fn ($c) => [$c['cliente'], $c['identificacion'], ...$this->dinero($c, ['facturado', 'notas_debito', 'notas_credito', 'retenciones', 'cobros', 'saldo']),
            ...$this->antiguedad($c), ...$this->dinero($c, ['anticipos_sin_aplicar', 'saldo_favor_nc', 'saldo_neto'])], $data['clientes']);
        $total = ['TOTAL', '', ...$this->dinero($t, ['facturado', 'notas_debito', 'notas_credito', 'retenciones', 'cobros', 'saldo']),
            ...$this->antiguedad($t), ...$this->dinero($t, ['anticipos_sin_aplicar', 'saldo_favor_nc', 'saldo_neto'])];

        return $this->entregar($r, 'Estado de cuenta de clientes', $headers, $rows, 'estado-cuenta-clientes', $total);
    }

    public function cliente(Request $r, Contact $contact)
    {
        $f = $this->filtros($r);
        $this->exigirDeLaEmpresa($contact, $f['company_id']);
        $data = EstadoCuenta::detalleCliente($f['company_id'], $contact, $f['desde'], $f['hasta']);
        if (! $this->exporta($r)) {
            return response()->json($data);
        }

        $archivo = 'estado-cuenta-cliente-'.Str::slug($contact->razon_social);
        $titulo = 'Estado de cuenta — '.$contact->razon_social;
        if ($r->input('vista') === 'movimientos') {
            return $this->entregarMovimientos($r, $titulo.' (movimientos)', $data['movimientos'], $archivo.'-movimientos', 'Factura');
        }

        $headers = ['Fecha', 'Factura', 'Total', 'Notas de crédito', 'Notas de débito', 'Retenciones', 'Cobros', 'Anticipos', 'Saldo', 'Días'];
        $rows = array_map(fn ($d) => [$d['fecha'], $d['numero'], $this->n($d['total']),
            $this->citar($d['notas_credito'], 'aplicado'), $this->citar($d['notas_debito'], 'total'), $this->citar($d['retenciones'], 'valor'),
            $this->n($d['cobros_total']), $this->n($d['anticipos_total']), $this->n($d['saldo']), $d['dias']], $data['documentos']);
        $s = $data['resumen'];
        $total = ['', 'TOTAL', $this->n($s['facturado']), $this->n($s['notas_credito']), $this->n($s['notas_debito']), $this->n($s['retenciones']),
            $this->n($s['cobros']), $this->n($s['anticipos_aplicados']), $this->n($s['saldo']), ''];
        // Lo que el cliente tiene a favor y todavía no se usó va en filas aparte, con el saldo neto al final
        $rows[] = 'Saldos a favor sin aplicar';
        foreach ($data['anticipos_sin_aplicar'] as $a) {
            $rows[] = [$a['fecha'], 'Anticipo ANT-'.$a['id'], '', '', '', '', '', '', $this->n(-$a['saldo']), ''];
        }
        foreach ($data['notas_credito_a_favor'] as $n) {
            $rows[] = [$n['fecha'], 'Nota de crédito '.$n['numero'], '', '', '', '', '', '', $this->n(-$n['saldo']), ''];
        }
        $rows[] = ['', 'SALDO NETO (saldo − saldos a favor)', '', '', '', '', '', '', $this->n($s['saldo_neto']), ''];

        return $this->entregar($r, $titulo, $headers, $rows, $archivo, $total);
    }

    public function proveedores(Request $r)
    {
        $f = $this->filtros($r);
        $data = EstadoCuenta::resumenProveedores($f['company_id'], $f['desde'], $f['hasta'], $f['buscar'], $f['con_saldo']);
        if (! $this->exporta($r)) {
            return response()->json($data);
        }

        $t = $data['totales'];
        $headers = ['Proveedor', 'Identificación', 'Compras', 'Retenciones', 'Pagos', 'Anticipos aplicados', 'Saldo',
            '0-30 días', '31-60 días', '61-90 días', 'Más de 90 días', 'Anticipos sin aplicar', 'Saldo neto'];
        $rows = array_map(fn ($p) => [$p['proveedor'], $p['identificacion'], ...$this->dinero($p, ['comprado', 'retenciones', 'pagos', 'anticipos_aplicados', 'saldo']),
            ...$this->antiguedad($p), ...$this->dinero($p, ['anticipos_sin_aplicar', 'saldo_neto'])], $data['proveedores']);
        $total = ['TOTAL', '', ...$this->dinero($t, ['comprado', 'retenciones', 'pagos', 'anticipos_aplicados', 'saldo']),
            ...$this->antiguedad($t), ...$this->dinero($t, ['anticipos_sin_aplicar', 'saldo_neto'])];

        return $this->entregar($r, 'Estado de cuenta de proveedores', $headers, $rows, 'estado-cuenta-proveedores', $total);
    }

    public function proveedor(Request $r, Contact $contact)
    {
        $f = $this->filtros($r);
        $this->exigirDeLaEmpresa($contact, $f['company_id']);
        $data = EstadoCuenta::detalleProveedor($f['company_id'], $contact, $f['desde'], $f['hasta']);
        if (! $this->exporta($r)) {
            return response()->json($data);
        }

        $archivo = 'estado-cuenta-proveedor-'.Str::slug($contact->razon_social);
        $titulo = 'Estado de cuenta — '.$contact->razon_social;
        if ($r->input('vista') === 'movimientos') {
            return $this->entregarMovimientos($r, $titulo.' (movimientos)', $data['movimientos'], $archivo.'-movimientos', 'Compra');
        }

        $headers = ['Fecha', 'Compra', 'Total', 'Retenciones', 'Pagos', 'Anticipos aplicados', 'Saldo', 'Días'];
        $rows = array_map(fn ($d) => [$d['fecha'], $d['numero'], $this->n($d['total']), $this->citar($d['retenciones'], 'valor'),
            $this->n($d['pagos_total']), $this->n($d['anticipos_total']), $this->n($d['saldo']), $d['dias']], $data['documentos']);
        $s = $data['resumen'];
        $total = ['', 'TOTAL', $this->n($s['comprado']), $this->n($s['retenciones']), $this->n($s['pagos']), $this->n($s['anticipos_aplicados']), $this->n($s['saldo']), ''];
        $rows[] = 'Anticipos entregados sin aplicar';
        foreach ($data['anticipos_sin_aplicar'] as $a) {
            $rows[] = [$a['fecha'], 'Anticipo ANT-'.$a['id'], '', '', '', '', $this->n(-$a['saldo']), ''];
        }
        $rows[] = ['', 'SALDO NETO (saldo − anticipos sin aplicar)', '', '', '', '', $this->n($s['saldo_neto']), ''];

        return $this->entregar($r, $titulo, $headers, $rows, $archivo, $total);
    }

    // ------------------------------------------------------------------ privados

    /** @return array{company_id:int,desde:?string,hasta:?string,buscar:?string,con_saldo:bool} */
    private function filtros(Request $r): array
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'con_saldo' => ['nullable', 'boolean'],
            'formato' => ['nullable', Rule::in(['excel', 'pdf', 'json'])],
            'vista' => ['nullable', Rule::in(['documentos', 'movimientos'])],
        ], [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        return [
            'company_id' => (int) $d['company_id'],
            'desde' => $d['desde'] ?? null,
            'hasta' => $d['hasta'] ?? null,
            'buscar' => $d['buscar'] ?? null,
            'con_saldo' => (bool) ($d['con_saldo'] ?? false),
        ];
    }

    /** Un cliente o proveedor de otra empresa no existe para esta: 404, sin dar ni un dato. */
    private function exigirDeLaEmpresa(Contact $contact, int $companyId): void
    {
        abort_unless((int) $contact->company_id === $companyId, 404, 'No se encontró el cliente o proveedor en esta empresa.');
    }

    /** ¿Piden un archivo? Sin formato (o formato=json) se devuelve el JSON de siempre. */
    private function exporta(Request $r): bool
    {
        return in_array($r->input('formato'), ['excel', 'pdf'], true);
    }

    /** Excel = CSV con marca UTF-8 (como los demás reportes); PDF = la misma tabla con sus totales. */
    private function entregar(Request $r, string $titulo, array $headers, array $rows, string $archivo, array $total)
    {
        if ($r->input('formato') === 'pdf') {
            // pdfResponse arma HTML con el texto tal cual: se escapa para que un "&" en un nombre no rompa la tabla
            $limpio = fn (array $fila) => array_map(fn ($c) => htmlspecialchars((string) $c), $fila);

            return $this->pdfResponse($titulo, $headers, array_map(fn ($f) => is_array($f) ? $limpio($f) : $f, $rows), $archivo, $limpio($total));
        }

        return $this->csvResponse($headers, array_merge($rows, [$total]), $archivo, true);
    }

    private function entregarMovimientos(Request $r, string $titulo, array $movimientos, string $archivo, string $documentoBase)
    {
        $headers = ['Fecha', 'Tipo', 'Documento', $documentoBase, 'Detalle', 'Cargo', 'Abono', 'Saldo'];
        $rows = array_map(fn ($m) => [$m['fecha'], $this->tipoMovimiento($m['tipo']), $m['documento'], $m['referencia'], $m['detalle'],
            $m['cargo'] > 0 ? $this->n($m['cargo']) : '', $m['abono'] > 0 ? $this->n($m['abono']) : '', $this->n($m['saldo'])], $movimientos);
        $saldoFinal = $movimientos ? end($movimientos)['saldo'] : 0;
        $total = ['', '', '', '', 'SALDO', '', '', $this->n($saldoFinal)];

        return $this->entregar($r, $titulo, $headers, $rows, $archivo, $total);
    }

    private function tipoMovimiento(string $tipo): string
    {
        return ['factura' => 'Factura', 'compra' => 'Compra', 'nota_debito' => 'Nota de débito', 'nota_credito' => 'Nota de crédito', 'retencion' => 'Retención',
            'cobro' => 'Cobro', 'pago' => 'Pago', 'cruce' => 'Cruce de saldos', 'anticipo' => 'Anticipo', 'ajuste' => 'Ajuste'][$tipo] ?? $tipo;
    }

    private function n(mixed $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }

    /** @return array<int,string> los campos de $fila con dos decimales, en ese orden */
    private function dinero(array $fila, array $campos): array
    {
        return array_map(fn ($c) => $this->n($fila[$c] ?? 0), $campos);
    }

    /** @return array<int,string> las cuatro columnas de antigüedad */
    private function antiguedad(array $fila): array
    {
        return array_map(fn ($t) => $this->n($fila['antiguedad'][$t] ?? 0), EstadoCuenta::TRAMOS);
    }

    /** "NCI-000001 (10.00); NCI-000002 (4.00)": los números de documento con su valor, para la celda de una factura. */
    private function citar(array $items, string $campoValor): string
    {
        return implode('; ', array_map(fn ($i) => $i['numero'].' ('.$this->n($i[$campoValor]).')', $items));
    }
}
