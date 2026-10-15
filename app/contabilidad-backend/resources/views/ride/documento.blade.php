{{--
    RIDE (representación impresa del comprobante electrónico) de una factura, una nota de crédito o una nota de débito.
    Solo dibuja los datos que arma App\Services\RideBuilder: no calcula nada ni decide qué es válido.
    Está escrita con tablas y CSS 2.1 porque la convierte dompdf (no entiende flex ni grid).
--}}
@php
    $e = $d['emisor'];
    $a = $d['autorizacion'];
    $c = $d['comprador'];
    $esMotivos = $d['detalle'] === 'motivos';
    // Solo un comprobante AUTORIZADO lleva número; si no lo está se dice que está pendiente, sin inventar nada
    $numeroAutorizacion = $a['autorizado'] ? ($a['numero'] ?: 'No disponible') : 'PENDIENTE DE AUTORIZACIÓN';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $d['tipo'] }} {{ $d['numero'] }}</title>
<style>
    @page { margin: 26px 28px 30px 28px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.6px; color: #000; line-height: 1.35; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; }
    .der { text-align: right; }
    .cen { text-align: center; }
    .fuerte { font-weight: bold; }

    .leyenda { border: 1.5px solid #b91c1c; background: #fef2f2; color: #991b1b; font-weight: bold; font-size: 8.4px;
        text-align: center; padding: 6px 8px; margin-bottom: 8px; }
    .aviso { border: 1.5px solid #92400e; background: #fffbeb; color: #78350f; font-weight: bold; font-size: 8px;
        text-align: center; padding: 5px 8px; margin-bottom: 8px; }

    .cabecera td.col { width: 50%; padding: 0; }
    .caja { border: 1px solid #000; padding: 7px 9px; }
    .caja-izq { margin-right: 5px; }
    .logo { max-width: 150px; max-height: 58px; margin-bottom: 6px; }
    .razon { font-size: 10.5px; font-weight: bold; }
    .comercial { font-size: 8.6px; margin-bottom: 4px; }
    .linea { margin-top: 2px; }

    .ruc { font-size: 9.5px; font-weight: bold; }
    .tipo { font-size: 12.5px; font-weight: bold; letter-spacing: 0.5px; margin-top: 3px; }
    .numdoc { font-size: 9px; font-weight: bold; margin-bottom: 5px; }
    .campo { margin-top: 4px; }
    .campo .et { font-size: 6.4px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.03em; }
    .campo .val { font-size: 7.8px; margin-top: 1px; }
    .clave { font-size: 6.9px; letter-spacing: 0; white-space: nowrap; }
    .barras { margin-top: 5px; height: 38px; width: 100%; }

    .bloque { border: 1px solid #000; padding: 5px 9px; margin-top: 7px; }
    .bloque td { padding: 1.5px 0; }
    .bloque .et { font-weight: bold; white-space: nowrap; padding-right: 6px; }

    table.detalle { margin-top: 7px; }
    table.detalle th { background: #ececec; border: 1px solid #000; padding: 4px 5px; font-size: 6.9px; text-align: left; }
    table.detalle td { border-left: 1px solid #000; border-right: 1px solid #000; border-bottom: 1px solid #d4d4d4; padding: 3px 5px; }
    table.detalle tr.ultima td { border-bottom: 1px solid #000; }

    .pie td.izq { width: 56%; padding-right: 6px; }
    .pie td.dcha { width: 44%; }
    .totales td { border: 1px solid #000; padding: 3px 7px; }
    .totales tr.total td { font-weight: bold; font-size: 8.6px; background: #ececec; }
    .subtitulo { font-weight: bold; text-transform: uppercase; font-size: 7px; border-bottom: 1px solid #000; padding-bottom: 2px; margin-bottom: 3px; }
    .adicional { margin-top: 7px; }
    .adicional td { padding: 1.5px 0; }
    .nota { margin-top: 8px; border-top: 1px solid #000; padding-top: 5px; font-weight: bold; font-size: 7px; }
</style>
</head>
<body>

@if($d['anulado'] && $d['aviso_anulado'])
    <div class="aviso">{{ $d['aviso_anulado'] }}</div>
@endif
@if($d['leyenda'])
    <div class="leyenda">{{ $d['leyenda'] }}</div>
@endif

{{-- ══ Cabecera: emisor (izquierda) y datos del comprobante y de la autorización (derecha) ══ --}}
<table class="cabecera">
    <tr>
        <td class="col">
            <div class="caja caja-izq">
                @if($e['logo'])<img class="logo" src="{{ $e['logo'] }}" alt="">@endif
                <div class="razon">{{ $e['razon_social'] }}</div>
                @if($e['nombre_comercial'])<div class="comercial">{{ $e['nombre_comercial'] }}</div>@endif
                <div class="linea"><b>Dirección matriz:</b> {{ $e['dir_matriz'] }}</div>
                <div class="linea"><b>Dirección sucursal:</b> {{ $e['dir_sucursal'] }}</div>
                @if($e['telefonos'])<div class="linea"><b>Teléfonos:</b> {{ $e['telefonos'] }}</div>@endif
                @if($e['email'])<div class="linea"><b>E-mail:</b> {{ $e['email'] }}</div>@endif
                @if($e['sitio_web'])<div class="linea">{{ $e['sitio_web'] }}</div>@endif
                @if($e['contribuyente_especial'])<div class="linea"><b>Contribuyente especial, resolución Nro.:</b> {{ $e['contribuyente_especial'] }}</div>@endif
                @if($e['agente_retencion'])<div class="linea"><b>Agente de retención, resolución Nro.:</b> {{ $e['agente_retencion'] }}</div>@endif
                @if($e['regimen'])<div class="linea fuerte">{{ $e['regimen'] }}</div>@endif
                <div class="linea"><b>OBLIGADO A LLEVAR CONTABILIDAD:</b> {{ $e['obligado_contabilidad'] }}</div>
            </div>
        </td>
        <td class="col">
            <div class="caja">
                <div class="ruc">R.U.C.: {{ $e['ruc'] }}</div>
                <div class="tipo">{{ $d['tipo'] }}</div>
                <div class="numdoc">No. {{ $d['numero'] }}</div>

                <div class="campo">
                    <div class="et">Número de autorización</div>
                    <div class="val mono">{{ $numeroAutorizacion }}</div>
                </div>
                <div class="campo">
                    <div class="et">Fecha y hora de autorización</div>
                    <div class="val">{{ $a['autorizado'] && $a['fecha_hora'] ? $a['fecha_hora'] : '—' }}</div>
                </div>
                <table style="margin-top: 4px;"><tr>
                    <td style="width: 50%;"><div class="et" style="font-size: 6.4px; font-weight: bold;">AMBIENTE</div><div class="val" style="font-size: 7.8px;">{{ $d['ambiente'] }}</div></td>
                    <td style="width: 50%;"><div class="et" style="font-size: 6.4px; font-weight: bold;">EMISIÓN</div><div class="val" style="font-size: 7.8px;">{{ $d['emision'] }}</div></td>
                </tr></table>
                <div class="campo">
                    <div class="et">Clave de acceso</div>
                    @if($d['clave_acceso'])
                        <div class="val mono clave">{{ $d['clave_acceso'] }}</div>
                        @if($d['codigo_barras'])<img class="barras" src="{{ $d['codigo_barras'] }}" alt="">@endif
                    @else
                        <div class="val">—</div>
                    @endif
                </div>
            </div>
        </td>
    </tr>
</table>

{{-- ══ Comprador ══ --}}
<div class="bloque">
    <table>
        <tr>
            <td class="et" style="width: 24%;">Razón social / nombres y apellidos:</td>
            <td style="width: 46%;">{{ $c['razon_social'] }}</td>
            <td class="et" style="width: 12%;">{{ $c['tipo_identificacion'] }}:</td>
            <td style="width: 18%;">{{ $c['identificacion'] }}</td>
        </tr>
        <tr>
            <td class="et">Fecha de emisión:</td>
            <td>{{ $d['fecha_emision'] }}</td>
            <td class="et">Teléfono:</td>
            <td>{{ $c['telefono'] ?: '—' }}</td>
        </tr>
        <tr>
            <td class="et">Dirección:</td>
            <td>{{ $c['direccion'] ?: '—' }}</td>
            <td class="et">E-mail:</td>
            <td>{{ $c['email'] ?: '—' }}</td>
        </tr>
        @if($d['modificado'])
        <tr>
            <td class="et">Comprobante que se modifica:</td>
            <td>{{ $d['modificado']['tipo'] }} No. {{ $d['modificado']['numero'] }}</td>
            <td class="et">Emitido el:</td>
            <td>{{ $d['modificado']['fecha'] ?: '—' }}</td>
        </tr>
        @endif
        @if($d['motivo'])
        <tr>
            <td class="et">Razón de la modificación:</td>
            <td colspan="3">{{ $d['motivo'] }}</td>
        </tr>
        @endif
    </table>
</div>

{{-- ══ Detalle ══ --}}
<table class="detalle">
    <thead>
    @if($esMotivos)
        <tr><th>Razón de la modificación</th><th class="der" style="width: 90px;">Valor</th></tr>
    @else
        <tr>
            <th style="width: 62px;">Código</th>
            <th>Descripción</th>
            <th class="der" style="width: 42px;">Cant.</th>
            <th class="der" style="width: 58px;">P. unitario</th>
            <th class="der" style="width: 50px;">Descuento</th>
            <th class="der" style="width: 62px;">Total</th>
        </tr>
    @endif
    </thead>
    <tbody>
    @foreach($d['lineas'] as $i => $l)
        @if($esMotivos)
            <tr class="{{ $loop->last ? 'ultima' : '' }}"><td>{{ $l['descripcion'] }}</td><td class="der">{{ $l['total'] }}</td></tr>
        @else
            <tr class="{{ $loop->last ? 'ultima' : '' }}">
                <td class="mono" style="font-size: 6.9px;">{{ $l['codigo'] }}</td>
                <td>{{ $l['descripcion'] }}</td>
                <td class="der">{{ $l['cantidad'] }}</td>
                <td class="der">{{ $l['precio_unitario'] }}</td>
                <td class="der">{{ $l['descuento'] }}</td>
                <td class="der">{{ $l['total'] }}</td>
            </tr>
        @endif
    @endforeach
    </tbody>
</table>

{{-- ══ Pie: formas de pago e información adicional (izquierda), totales (derecha) ══ --}}
<table class="pie" style="margin-top: 8px;">
    <tr>
        <td class="izq">
            @if(count($d['pagos']))
                <div class="subtitulo">Formas de pago</div>
                <table>
                    @foreach($d['pagos'] as $p)
                        <tr><td>{{ $p['forma'] }}</td><td class="der" style="width: 55px;">{{ $p['valor'] }}</td></tr>
                    @endforeach
                </table>
                @if($d['condicion_pago'])<div style="margin-top: 2px;"><b>Condición de pago:</b> {{ $d['condicion_pago'] }}</div>@endif
            @endif
            @if(count($d['info_adicional']))
                <div class="adicional">
                    <div class="subtitulo">Información adicional</div>
                    <table>
                        @foreach($d['info_adicional'] as $f)
                            <tr><td class="fuerte" style="width: 52px;">{{ $f['nombre'] }}:</td><td>{{ $f['valor'] }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </td>
        <td class="dcha">
            <table class="totales">
                @foreach($d['totales'] as $t)
                    <tr class="{{ $t['fuerte'] ? 'total' : '' }}"><td>{{ $t['etiqueta'] }}</td><td class="der" style="width: 70px;">{{ $t['valor'] }}</td></tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>

@if($d['nota_pie'])
    <div class="nota">{{ $d['nota_pie'] }}</div>
@endif

</body>
</html>
