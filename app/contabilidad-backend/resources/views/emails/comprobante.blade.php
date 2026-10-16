{{--
    Correo con un comprobante electrónico. Trato formal, en español neutro. Sin imágenes remotas ni pixeles de rastreo:
    solo texto y tablas simples, que se ven igual en cualquier lector de correo.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $info['asunto'] }}</title>
</head>
<body style="margin:0; padding:0; background:#f3f4f6; font-family: Arial, Helvetica, sans-serif; color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6; padding:24px 0;">
<tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background:#ffffff; border:1px solid #e5e7eb;">
        <tr>
            <td style="padding:20px 28px; border-bottom:3px solid #1d4ed8;">
                <div style="font-size:18px; font-weight:bold; color:#111827;">{{ $info['emisor'] }}</div>
                <div style="font-size:12px; color:#6b7280; margin-top:2px;">R.U.C. {{ $info['emisor_ruc'] }}</div>
            </td>
        </tr>
        <tr>
            <td style="padding:24px 28px; font-size:14px; line-height:1.55;">
                <p style="margin:0 0 14px;">Estimado(a) {{ $info['cliente'] }}:</p>
                <p style="margin:0 0 14px;">
                    {{ $info['emisor'] }} le hace llegar su {{ mb_strtolower($info['tipo']) }} electrónica
                    No. <b>{{ $info['numero'] }}</b>, emitida el {{ $info['fecha'] }}.
                </p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb; font-size:13px; margin:0 0 16px;">
                    <tr>
                        <td style="padding:8px 12px; background:#f9fafb; width:42%; border-bottom:1px solid #e5e7eb;">Comprobante</td>
                        <td style="padding:8px 12px; border-bottom:1px solid #e5e7eb;">{{ $info['tipo'] }} No. {{ $info['numero'] }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 12px; background:#f9fafb; border-bottom:1px solid #e5e7eb;">Fecha de emisión</td>
                        <td style="padding:8px 12px; border-bottom:1px solid #e5e7eb;">{{ $info['fecha'] }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 12px; background:#f9fafb; {{ $info['autorizado'] ? 'border-bottom:1px solid #e5e7eb;' : '' }}">{{ $info['etiqueta_total'] }}</td>
                        <td style="padding:8px 12px; {{ $info['autorizado'] ? 'border-bottom:1px solid #e5e7eb;' : '' }}"><b>${{ $info['total'] }}</b></td>
                    </tr>
                    @if($info['autorizado'])
                    <tr>
                        <td style="padding:8px 12px; background:#f9fafb; border-bottom:1px solid #e5e7eb;">Número de autorización</td>
                        <td style="padding:8px 12px; border-bottom:1px solid #e5e7eb; font-family:Consolas, Menlo, monospace; font-size:12px; word-break:break-all;">{{ $info['numero_autorizacion'] ?: 'No disponible' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 12px; background:#f9fafb;">Fecha de autorización</td>
                        <td style="padding:8px 12px;">{{ $info['fecha_autorizacion'] ?: 'No disponible' }}</td>
                    </tr>
                    @endif
                </table>

                @if(! $info['autorizado'])
                <p style="margin:0 0 14px; padding:10px 12px; background:#fffbeb; border:1px solid #f59e0b; color:#78350f;">
                    Este comprobante todavía está <b>pendiente de autorización del SRI</b>: aún no tiene validez tributaria.
                    Los archivos adjuntos no llevan número de autorización; en cuanto el SRI lo autorice se le podrá remitir la versión autorizada.
                </p>
                @endif

                <p style="margin:0 0 6px;">Adjuntamos a este correo:</p>
                <ul style="margin:0 0 14px; padding-left:20px;">
                    @foreach($info['adjuntos'] as $a)
                        <li>{{ $a }}</li>
                    @endforeach
                </ul>

                <p style="margin:0 0 14px;">
                    Conserve estos archivos: el XML es el comprobante electrónico que respalda su transacción
                    ante el SRI y el PDF (RIDE) es su representación impresa.
                </p>
                <p style="margin:0;">Atentamente,<br><b>{{ $info['emisor'] }}</b></p>
            </td>
        </tr>
        <tr>
            <td style="padding:14px 28px; background:#f9fafb; border-top:1px solid #e5e7eb; font-size:11px; color:#6b7280; line-height:1.5;">
                @if($info['contacto'])Si tiene alguna consulta, escríbanos a {{ $info['contacto'] }}.<br>@endif
                Este mensaje se generó automáticamente desde el sistema de facturación electrónica de {{ $info['emisor'] }}.
            </td>
        </tr>
    </table>
</td></tr>
</table>
</body>
</html>
