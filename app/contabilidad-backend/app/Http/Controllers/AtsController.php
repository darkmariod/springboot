<?php
namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * ATS — Anexo Transaccional Simplificado.
 * Genera el XML que se sube al portal del SRI (compras, ventas y anulados
 * del período). No es un resumen: es el archivo que el contador presenta.
 */
class AtsController extends Controller
{
    public function xml(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'anio'       => ['required', 'integer', 'min:2020', 'max:2035'],
            'mes'        => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $company = Company::findOrFail($d['company_id']);
        $desde = Carbon::create($d['anio'], $d['mes'], 1)->startOfMonth();
        $hasta = (clone $desde)->endOfMonth();
        $mes = str_pad((string) $d['mes'], 2, '0', STR_PAD_LEFT);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $iva = $doc->createElement('iva');
        $doc->appendChild($iva);

        $add = fn (\DOMElement $p, string $n, $v = null) => $p->appendChild(
            $v === null ? $doc->createElement($n) : $doc->createElement($n, htmlspecialchars((string) $v))
        );

        // ── Cabecera ──
        $add($iva, 'TipoIDInformante', 'R');
        $add($iva, 'IdInformante', $company->ruc);
        $add($iva, 'razonSocial', $company->razon_social);
        $add($iva, 'Anio', $d['anio']);
        $add($iva, 'Mes', $mes);
        $add($iva, 'numEstabRuc', str_pad((string) \App\Models\Branch::where('company_id', $company->id)->count() ?: 1, 3, '0', STR_PAD_LEFT));

        $ventas = Invoice::with('contact')->where('company_id', $company->id)
            ->whereBetween('fecha_emision', [$desde, $hasta])->get();
        $compras = Purchase::with('contact')->where('company_id', $company->id)
            ->whereBetween('fecha_emision', [$desde, $hasta])->get();

        $add($iva, 'totalVentas', number_format((float) $ventas->where('estado', '!=', 'anulado')->sum('total_sin_impuestos'), 2, '.', ''));
        $add($iva, 'codigoOperativo', 'IVA');

        // ── Compras ──
        $nodoCompras = $add($iva, 'compras');
        foreach ($compras as $c) {
            $det = $add($nodoCompras, 'detalleCompras');
            $add($det, 'codSustento', '01');
            $add($det, 'tpIdProv', $this->tipoId($c->contact?->tipo_identificacion));
            $add($det, 'idProv', $c->contact?->identificacion);
            $add($det, 'tipoComprobante', '01');
            $add($det, 'fechaRegistro', Carbon::parse($c->fecha_emision)->format('d/m/Y'));
            $add($det, 'establecimiento', substr((string) $c->numero, 0, 3));
            $add($det, 'puntoEmision', substr((string) $c->numero, 4, 3));
            $add($det, 'secuencial', substr((string) $c->numero, 8));
            $add($det, 'fechaEmision', Carbon::parse($c->fecha_emision)->format('d/m/Y'));
            $add($det, 'autorizacion', $c->clave_acceso);
            $add($det, 'baseNoGraIva', '0.00');
            $add($det, 'baseImponible', '0.00');
            $add($det, 'baseImpGrav', number_format((float) $c->total_sin_impuestos, 2, '.', ''));
            $add($det, 'baseImpExe', '0.00');
            $add($det, 'montoIce', '0.00');
            $add($det, 'montoIva', number_format((float) $c->total_impuesto, 2, '.', ''));
            $add($det, 'valRetBien10', '0.00');
            $add($det, 'valRetServ20', '0.00');
            $add($det, 'valorRetBienes', '0.00');
            $add($det, 'valRetServ50', '0.00');
            $add($det, 'valorRetServicios', '0.00');
            $add($det, 'valRetServ100', '0.00');
            $add($det, 'totbasesImpReemb', '0.00');
            $add($det, 'pagoLocExt', '01');
            $add($det, 'formaPago', '01');
        }

        // ── Ventas ──
        $nodoVentas = $add($iva, 'ventas');
        foreach ($ventas->where('estado', '!=', 'anulado') as $v) {
            $det = $add($nodoVentas, 'detalleVentas');
            $add($det, 'tpIdCliente', $this->tipoId($v->contact?->tipo_identificacion));
            $add($det, 'idCliente', $v->contact?->identificacion);
            $add($det, 'parteRelVtas', 'NO');
            $add($det, 'tipoComprobante', '18');   // 18 = factura
            $add($det, 'tipoEmision', 'F');
            $add($det, 'numeroComprobantes', '1');
            $add($det, 'baseNoGraIva', '0.00');
            $add($det, 'baseImponible', '0.00');
            $add($det, 'baseImpGrav', number_format((float) $v->total_sin_impuestos, 2, '.', ''));
            $add($det, 'montoIva', number_format((float) $v->total_impuesto, 2, '.', ''));
            $add($det, 'montoIce', '0.00');
            $add($det, 'valorRetIva', '0.00');
            $add($det, 'valorRetRenta', '0.00');
            $formas = $add($det, 'formasDePago');
            $add($formas, 'formaPago', $v->forma_pago === 'efectivo' ? '01' : '20');
        }

        // ── Anulados ──
        $nodoAnulados = $add($iva, 'ventasAnuladas');
        foreach ($ventas->where('estado', 'anulado') as $a) {
            $det = $add($nodoAnulados, 'detalleAnulados');
            $add($det, 'tipoComprobante', '18');
            $add($det, 'establecimiento', substr((string) $a->numero, 0, 3));
            $add($det, 'puntoEmision', substr((string) $a->numero, 4, 3));
            $add($det, 'secuencialInicio', substr((string) $a->numero, 8));
            $add($det, 'secuencialFin', substr((string) $a->numero, 8));
            $add($det, 'autorizacion', $a->sriDocument?->numero_autorizacion ?? '');
        }

        $nombre = 'ATS_'.$company->ruc.'_'.$d['anio'].$mes.'.xml';

        return response($doc->saveXML(), 200, [
            'Content-Type'        => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
        ]);
    }

    /** Tipo de identificación en el formato del ATS: R ruc, C cédula, P pasaporte. */
    private function tipoId(?string $t): string
    {
        return match ($t) { '04' => 'R', '05' => 'C', '06' => 'P', default => 'C' };
    }
}
