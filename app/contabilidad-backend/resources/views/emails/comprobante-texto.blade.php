Estimado(a) {!! $info['cliente'] !!}:

{!! $info['emisor'] !!} le hace llegar su {!! mb_strtolower($info['tipo']) !!} electrónica No. {!! $info['numero'] !!}, emitida el {!! $info['fecha'] !!}.

{!! $info['etiqueta_total'] !!}: ${!! $info['total'] !!}
@if($info['autorizado'])
Número de autorización: {!! $info['numero_autorizacion'] ?: 'No disponible' !!}
Fecha de autorización: {!! $info['fecha_autorizacion'] ?: 'No disponible' !!}
@else

Este comprobante todavía está pendiente de autorización del SRI: aún no tiene validez tributaria. Los archivos adjuntos no llevan número de autorización; en cuanto el SRI lo autorice se le podrá remitir la versión autorizada.
@endif

Adjuntamos a este correo:
@foreach($info['adjuntos'] as $a)
- {!! $a !!}
@endforeach

Conserve estos archivos: el XML es el comprobante electrónico que respalda su transacción ante el SRI y el PDF (RIDE) es su representación impresa.

Atentamente,
{!! $info['emisor'] !!}
@if($info['contacto'])

Si tiene alguna consulta, escríbanos a {!! $info['contacto'] !!}.
@endif
