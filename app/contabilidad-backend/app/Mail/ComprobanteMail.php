<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo con un comprobante electrónico: el RIDE (PDF) y el XML adjuntos. Se envía por el servidor de correo de la empresa
 * emisora (ver EnviarComprobanteCorreo), con su razón social como remitente. Sin imágenes remotas ni rastreo.
 */
class ComprobanteMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $info     lo que dibujan las vistas emails.comprobante y emails.comprobante-texto
     * @param  array<int, array{nombre:string,mime:string,datos:string}>  $archivos  los adjuntos, ya generados
     */
    public function __construct(
        public string $asunto,
        public Address $remitente,
        public ?Address $responderA,
        public array $info,
        protected array $archivos = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->remitente,
            replyTo: $this->responderA ? [$this->responderA] : [],
            subject: $this->asunto,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.comprobante', text: 'emails.comprobante-texto', with: ['info' => $this->info]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (array $a) => Attachment::fromData(fn () => $a['datos'], $a['nombre'])->withMime($a['mime']),
            $this->archivos,
        );
    }

    /** Nombres de los archivos adjuntos, en orden. */
    public function nombresDeAdjuntos(): array
    {
        return array_column($this->archivos, 'nombre');
    }
}
