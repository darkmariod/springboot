<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\SriDocument;
use App\Support\Code128;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Prepara los datos del RIDE (la representación impresa del comprobante electrónico) de una factura, una nota de crédito
 * o una nota de débito. La vista resources/views/ride/documento.blade.php solo los dibuja.
 *
 * Lo que sale de la autorización del SRI se toma SOLO del comprobante guardado (SriDocument): número de autorización y fecha
 * y hora tal como los devolvió el SRI. Si el documento todavía no está autorizado (sin certificado, generado, firmado, enviado
 * o rechazado) no se inventa ningún número ni fecha: el RIDE lleva una leyenda que dice que no tiene validez tributaria.
 */
class RideBuilder
{
    public const LEYENDA_PENDIENTE = 'Documento pendiente de autorización del SRI — sin validez tributaria hasta ser autorizado';
    public const LEYENDA_RECHAZADO = 'El SRI no autorizó este comprobante — sin validez tributaria';
    public const LEYENDA_SIN_COMPROBANTE = 'Documento sin comprobante electrónico del SRI — sin validez tributaria';

    private const TIPOS_IDENTIFICACION = ['04' => 'RUC', '05' => 'CÉDULA', '06' => 'PASAPORTE', '07' => 'CONSUMIDOR FINAL', '08' => 'IDENTIFICACIÓN DEL EXTERIOR'];
    private const FORMAS_PAGO = [
        '01' => 'SIN UTILIZACIÓN DEL SISTEMA FINANCIERO',
        '19' => 'TARJETA DE CRÉDITO',
        '20' => 'OTROS CON UTILIZACIÓN DEL SISTEMA FINANCIERO',
    ];
    private const REGIMENES = [
        '1' => 'CONTRIBUYENTE RÉGIMEN MICROEMPRESAS',
        '2' => 'CONTRIBUYENTE RÉGIMEN RIMPE',
        '3' => 'CONTRIBUYENTE NEGOCIO POPULAR - RÉGIMEN RIMPE',
    ];

    public function __construct(private DocumentCalculator $calc) {}

    // ------------------------------------------------------------ factura

    public function paraFactura(Invoice $f): array
    {
        $f->loadMissing('contact', 'branch', 'sriDocument');
        $company = Company::findOrFail($f->company_id);
        $items = $this->itemsDe($f->items);
        $calculo = $this->calcular($items);

        $d = $this->base($company, $f->sriDocument, $f->branch?->direccion);
        $d += [
            'tipo' => 'FACTURA',
            'numero' => $f->numero,
            'fecha_emision' => $this->fecha($f->fecha_emision),
            'comprador' => $this->comprador($f->contact),
            'detalle' => 'items',
            'lineas' => $this->lineas($items),
            'modificado' => null,
            'motivo' => null,
            'totales' => $this->totales($this->gruposDe($calculo, (float) $f->total_sin_impuestos, (float) $f->total_impuesto),
                (float) $f->total_sin_impuestos, (float) ($calculo['total_descuento'] ?? 0), $this->ice($items), (float) $f->importe_total),
            'pagos' => $this->pagos($f->forma_pago, (float) $f->importe_total),
            'condicion_pago' => $f->forma_pago === 'credito' ? 'CRÉDITO' : 'CONTADO',
        ];
        $d['info_adicional'] = $this->infoAdicional($f->contact);

        return $this->conEstado($d, $f->estado === 'anulado', 'factura');
    }

    // ------------------------------------------------------------ nota de crédito

    public function paraNotaCredito(CreditNote $n): array
    {
        $n->loadMissing('contact', 'invoice', 'sriDocument');
        $company = Company::findOrFail($n->company_id);
        $interna = $n->tipo === 'interna' || str_starts_with((string) $n->numero, 'NCI-');
        $items = $this->itemsDe($n->items);
        $calculo = $this->calcular($items);
        $lineas = $this->lineas($items);
        if (! $lineas) {
            // Una nota por valor suelto no trae líneas: se muestra el motivo como único renglón
            $lineas = [['codigo' => '—', 'descripcion' => (string) $n->motivo, 'cantidad' => '1.00',
                'precio_unitario' => $this->m($n->total_sin_impuestos ?? $n->importe_total), 'descuento' => '0.00',
                'total' => $this->m($n->total_sin_impuestos ?? $n->importe_total)]];
        }
        $sinImpuestos = (float) ($n->total_sin_impuestos ?? $n->importe_total);

        $d = $this->base($company, $interna ? null : $n->sriDocument, null);
        $d += [
            'tipo' => 'NOTA DE CRÉDITO'.($interna ? ' INTERNA' : ''),
            'numero' => $n->numero ?: 'SIN NÚMERO',
            'fecha_emision' => $this->fecha($n->fecha),
            'comprador' => $this->comprador($n->contact),
            'detalle' => 'items',
            'lineas' => $lineas,
            'modificado' => $n->invoice ? ['tipo' => 'FACTURA', 'numero' => $n->invoice->numero, 'fecha' => $this->fecha($n->invoice->fecha_emision)] : null,
            'motivo' => $n->motivo,
            'totales' => $this->totales($this->gruposDe($calculo, $sinImpuestos, (float) $n->total_impuesto),
                $sinImpuestos, (float) ($calculo['total_descuento'] ?? 0), $this->ice($items), (float) $n->importe_total),
            'pagos' => [],
            'condicion_pago' => null,
        ];
        $d['info_adicional'] = $this->infoAdicional($n->contact);

        return $this->conEstado($d, $n->tipo === 'anulado', $interna ? 'interna' : 'nota de crédito');
    }

    // ------------------------------------------------------------ nota de débito (vive en la tabla de facturas)

    public function paraNotaDebito(Invoice $nd): array
    {
        $nd->loadMissing('contact', 'sriDocument');
        $company = Company::findOrFail($nd->company_id);
        $interna = $nd->tipo_comprobante === 'nota_debito_interna';
        $motivos = collect($nd->items ?? [])->map(fn ($m) => [
            'razon' => (string) ($m['razon'] ?? $m['descripcion'] ?? ''),
            'valor' => round((float) ($m['valor'] ?? $m['importe_total'] ?? 0), 2),
            'tarifa' => (float) ($m['tarifa'] ?? 0),
        ])->values();

        $grupos = [];
        foreach ($motivos->groupBy(fn ($m) => (string) $m['tarifa']) as $tarifa => $g) {
            $base = round($g->sum('valor'), 2);
            $grupos[] = ['codigo' => DocumentCalculator::codigoDeTarifa((float) $tarifa) ?? '4', 'base' => $base,
                'iva' => round($g->sum(fn ($m) => round($m['valor'] * $m['tarifa'] / 100, 2)), 2)];
        }
        $factura = $nd->factura_referencia_id ? Invoice::find($nd->factura_referencia_id) : null;

        $d = $this->base($company, $interna ? null : $nd->sriDocument, null);
        $d += [
            'tipo' => 'NOTA DE DÉBITO'.($interna ? ' INTERNA' : ''),
            'numero' => $nd->numero,
            'fecha_emision' => $this->fecha($nd->fecha_emision),
            'comprador' => $this->comprador($nd->contact),
            'detalle' => 'motivos',
            'lineas' => $motivos->map(fn ($m) => ['descripcion' => $m['razon'], 'total' => $this->m($m['valor'])])->all(),
            'modificado' => ['tipo' => 'FACTURA', 'numero' => $factura?->numero ?? $nd->numero_referencia,
                'fecha' => $factura ? $this->fecha($factura->fecha_emision) : null],
            'motivo' => null,
            'totales' => $this->totales($grupos, round($motivos->sum('valor'), 2), 0.0, 0.0, (float) $nd->importe_total),
            'pagos' => $nd->forma_pago ? $this->pagos((string) $nd->forma_pago, (float) $nd->importe_total) : [],
            'condicion_pago' => null,
        ];
        $d['info_adicional'] = $this->infoAdicional($nd->contact);

        return $this->conEstado($d, $nd->estado === 'anulado', $interna ? 'interna' : 'nota de débito');
    }

    // ------------------------------------------------------------ emisor, autorización y leyendas

    /** Emisor y bloque de autorización: lo común a los tres documentos. */
    private function base(Company $c, ?SriDocument $sri, ?string $dirSucursal): array
    {
        $regimen = trim((string) $c->regimen);
        $ambiente = (int) ($sri?->ambiente ?: $c->ambiente);
        $clave = $sri && trim((string) $sri->clave_acceso) !== '' ? trim((string) $sri->clave_acceso) : null;

        return [
            'emisor' => [
                'razon_social' => (string) $c->razon_social,
                'nombre_comercial' => trim((string) $c->nombre_comercial) !== '' ? (string) $c->nombre_comercial : null,
                'ruc' => (string) $c->ruc,
                'dir_matriz' => (string) $c->dir_matriz,
                'dir_sucursal' => trim((string) $dirSucursal) !== '' ? (string) $dirSucursal : (string) $c->dir_matriz,
                'obligado_contabilidad' => $c->obligado_contabilidad ? 'SI' : 'NO',
                'contribuyente_especial' => trim((string) $c->contribuyente_especial) ?: null,
                'agente_retencion' => trim((string) $c->agente_retencion) ?: null,
                'regimen' => $regimen === '' ? null : (self::REGIMENES[$regimen] ?? mb_strtoupper($regimen)),
                'telefonos' => trim((string) $c->telefonos) ?: null,
                'email' => trim((string) $c->email_envio) ?: null,
                'sitio_web' => trim((string) $c->sitio_web) ?: null,
                'logo' => str_starts_with((string) $c->logo, 'data:image/') ? $c->logo : null,
            ],
            'ambiente' => $ambiente === 2 ? 'PRODUCCIÓN' : 'PRUEBAS',
            'emision' => 'NORMAL',
            'clave_acceso' => $clave,
            'codigo_barras' => $clave && ctype_digit($clave) ? Code128::dataUri($clave, 44, 2) : null,
            'autorizacion' => $this->autorizacion($sri),
            'nota_pie' => trim((string) $c->nota_pie) ?: null,
        ];
    }

    /**
     * Estado de la autorización según el comprobante guardado. Solo un comprobante AUTORIZADO lleva número y fecha; el número
     * es el que guardó el sistema al consultar al SRI y la fecha la que el SRI devolvió. Nada más se inventa.
     *
     * @return array{autorizado:bool,estado:string,numero:?string,fecha_hora:?string,leyenda:?string}
     */
    private function autorizacion(?SriDocument $sri): array
    {
        if (! $sri) {
            return ['autorizado' => false, 'estado' => 'sin_comprobante', 'numero' => null, 'fecha_hora' => null, 'leyenda' => self::LEYENDA_SIN_COMPROBANTE];
        }
        if ($sri->autorizado()) {
            $numero = trim((string) $sri->numero_autorizacion);

            return ['autorizado' => true, 'estado' => 'autorizado', 'numero' => $numero !== '' ? $numero : null,
                'fecha_hora' => $this->fechaAutorizacion($sri->mensajes), 'leyenda' => null];
        }
        if ($sri->rechazado()) {
            return ['autorizado' => false, 'estado' => 'rechazado', 'numero' => null, 'fecha_hora' => null, 'leyenda' => self::LEYENDA_RECHAZADO];
        }

        return ['autorizado' => false, 'estado' => 'pendiente', 'numero' => null, 'fecha_hora' => null, 'leyenda' => self::LEYENDA_PENDIENTE];
    }

    /** La fecha y hora que el SRI devolvió al autorizar (dd/mm/aaaa hh:mm:ss), o null si la respuesta guardada no la trae. */
    private function fechaAutorizacion(mixed $mensajes): ?string
    {
        $nodo = is_array($mensajes) ? ($mensajes['RespuestaAutorizacionComprobante']['autorizaciones']['autorizacion'] ?? $mensajes) : null;
        if (is_array($nodo) && ! isset($nodo['fechaAutorizacion'])) {
            $nodo = $nodo[0] ?? null;
        }
        $valor = is_array($nodo) ? ($nodo['fechaAutorizacion'] ?? null) : null;
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }
        try {
            return Carbon::parse($valor)->format('d/m/Y H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Anulado y la leyenda que corresponde: la del estado de la autorización y, si está anulado, el aviso del portal del SRI. */
    private function conEstado(array $d, bool $anulado, string $que): array
    {
        $d['anulado'] = $anulado;
        $d['aviso_anulado'] = null;
        if ($anulado) {
            $d['aviso_anulado'] = 'DOCUMENTO ANULADO EN EL SISTEMA'.($d['autorizacion']['autorizado']
                ? ' — el SRI aún lo tiene autorizado: debe anularse también en el portal SRI en línea' : '');
        }
        $d['leyenda'] = $d['autorizacion']['leyenda'];
        if ($que === 'interna' && ! $d['autorizacion']['autorizado']) {
            $d['leyenda'] = 'Documento interno: no se envía al SRI — sin validez tributaria';
        }

        return $d;
    }

    // ------------------------------------------------------------ comprador, líneas y totales

    private function comprador(?Contact $c): array
    {
        return [
            'razon_social' => (string) ($c?->razon_social ?? ''),
            'identificacion' => (string) ($c?->identificacion ?? ''),
            'tipo_identificacion' => self::TIPOS_IDENTIFICACION[$c?->tipo_identificacion ?? ''] ?? 'IDENTIFICACIÓN',
            'direccion' => trim((string) $c?->direccion) ?: null,
            'email' => trim((string) $c?->email) ?: null,
            'telefono' => trim((string) $c?->telefono) ?: null,
        ];
    }

    private function infoAdicional(?Contact $c): array
    {
        $filas = [];
        foreach (['Dirección' => $c?->direccion, 'Teléfono' => $c?->telefono, 'Email' => $c?->email] as $nombre => $valor) {
            if (trim((string) $valor) !== '') {
                $filas[] = ['nombre' => $nombre, 'valor' => trim((string) $valor)];
            }
        }

        return $filas;
    }

    /** @return array<int, array{codigo:string,descripcion:string,cantidad:string,precio_unitario:string,descuento:string,total:string}> */
    private function lineas(array $items): array
    {
        return array_map(function ($it) {
            $cant = (float) ($it['cantidad'] ?? 0);
            $precio = (float) ($it['precio_unitario'] ?? 0);
            $desc = round((float) ($it['descuento'] ?? 0), 2);

            return [
                'codigo' => (string) ($it['codigo_principal'] ?? ''),
                'descripcion' => (string) ($it['descripcion'] ?? ''),
                'cantidad' => $this->cantidad($cant),
                'precio_unitario' => $this->m($precio),
                'descuento' => $this->m($desc),
                'total' => $this->m(round($cant * $precio - $desc, 2)),
            ];
        }, $items);
    }

    /** Los renglones guardados en el documento (descarta lo que no sea un renglón). */
    private function itemsDe(mixed $items): array
    {
        return array_values(array_filter(is_array($items) ? $items : [], 'is_array'));
    }

    private function ice(array $items): float
    {
        return round(array_sum(array_map(fn ($it) => (float) ($it['ice'] ?? 0), $items)), 2);
    }

    /** Los mismos cálculos con que se armó el comprobante; si alguna línea ya no cuadra con el SRI se cae a los totales guardados. */
    private function calcular(array $items): ?array
    {
        if (! $items) {
            return null;
        }
        try {
            return $this->calc->fromItems($items);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Bases e IVA por código de porcentaje. Sin líneas (o con líneas que no se pueden recalcular) se usan los totales guardados:
     * al 15% si cobró IVA, al 0% si no.
     *
     * @return array<int, array{codigo:string,base:float,iva:float}>
     */
    private function gruposDe(?array $calculo, float $sinImpuestos, float $impuesto): array
    {
        if ($calculo) {
            return array_map(fn ($t) => ['codigo' => (string) $t['codigoPorcentaje'], 'base' => (float) $t['baseImponible'], 'iva' => (float) $t['valor']], $calculo['impuestos']);
        }

        return [['codigo' => $impuesto > 0 ? '4' : '0', 'base' => $sinImpuestos, 'iva' => $impuesto]];
    }

    /**
     * El cuadro de totales del RIDE: subtotal por tarifa, no objeto, exento, sin impuestos, descuento, ICE (solo si hay),
     * IVA por tarifa y valor total. Siempre salen el 15% y el 0%; otras tarifas solo si el documento las usa.
     *
     * @param array<int, array{codigo:string,base:float,iva:float}> $grupos
     * @return array<int, array{etiqueta:string,valor:string,fuerte:bool}>
     */
    private function totales(array $grupos, float $sinImpuestos, float $descuento, float $ice, float $total): array
    {
        $base = []; $iva = []; $noObjeto = 0.0; $exento = 0.0;
        foreach ($grupos as $g) {
            if ($g['codigo'] === '6') { $noObjeto += $g['base']; continue; }
            if ($g['codigo'] === '7') { $exento += $g['base']; continue; }
            $tarifa = DocumentCalculator::TARIFA_POR_CODIGO[$g['codigo']] ?? 15;
            $base[$tarifa] = ($base[$tarifa] ?? 0.0) + $g['base'];
            $iva[$tarifa] = ($iva[$tarifa] ?? 0.0) + $g['iva'];
        }
        $base[15] ??= 0.0; $iva[15] ??= 0.0; $base[0] ??= 0.0;
        ksort($base); ksort($iva);

        $f = [];
        foreach ($base as $t => $v) {
            if ($t > 0) { $f[] = ['etiqueta' => 'SUBTOTAL '.$t.'%', 'valor' => $this->m($v), 'fuerte' => false]; }
        }
        $f[] = ['etiqueta' => 'SUBTOTAL 0%', 'valor' => $this->m($base[0]), 'fuerte' => false];
        $f[] = ['etiqueta' => 'SUBTOTAL NO OBJETO DE IVA', 'valor' => $this->m($noObjeto), 'fuerte' => false];
        $f[] = ['etiqueta' => 'SUBTOTAL EXENTO DE IVA', 'valor' => $this->m($exento), 'fuerte' => false];
        $f[] = ['etiqueta' => 'SUBTOTAL SIN IMPUESTOS', 'valor' => $this->m($sinImpuestos), 'fuerte' => false];
        $f[] = ['etiqueta' => 'TOTAL DESCUENTO', 'valor' => $this->m($descuento), 'fuerte' => false];
        if ($ice > 0) {
            $f[] = ['etiqueta' => 'ICE', 'valor' => $this->m($ice), 'fuerte' => false];
        }
        foreach ($iva as $t => $v) {
            if ($t > 0) { $f[] = ['etiqueta' => 'IVA '.$t.'%', 'valor' => $this->m($v), 'fuerte' => false]; }
        }
        $f[] = ['etiqueta' => 'PROPINA', 'valor' => '0.00', 'fuerte' => false];
        $f[] = ['etiqueta' => 'VALOR TOTAL', 'valor' => $this->m($total), 'fuerte' => true];

        return $f;
    }

    /** Formas de pago como las manda el emisor al SRI (InvoiceEmitter::SRI_FORMA_PAGO): código, nombre y valor. */
    private function pagos(?string $forma, float $total): array
    {
        $codigo = InvoiceEmitter::SRI_FORMA_PAGO[$forma ?? 'efectivo'] ?? (isset(self::FORMAS_PAGO[$forma ?? '']) ? $forma : '01');

        return [['codigo' => $codigo, 'forma' => self::FORMAS_PAGO[$codigo] ?? $codigo, 'valor' => $this->m($total)]];
    }

    // ------------------------------------------------------------ formato

    private function m(mixed $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    /** Cantidad con 2 decimales, o hasta 4 si el renglón los usa (así 1.5 se ve 1.50 y 0.125 se ve 0.125). */
    private function cantidad(float $v): string
    {
        $t = rtrim(number_format($v, 4, '.', ''), '0');
        $dec = strlen(substr($t, strpos($t, '.') + 1));

        return number_format($v, max(2, $dec), '.', '');
    }

    private function fecha(mixed $f): string
    {
        return $f ? Carbon::parse($f)->format('d/m/Y') : '';
    }
}
