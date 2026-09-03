<?php

namespace Packages\Kilkana\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Un número del boletín en la bandeja de un suscriptor.
 *
 * Todo lo que lleva viene resuelto: las piezas con su enlace ya contado, el píxel de apertura
 * y la baja firmada, cada uno para ESTE suscriptor. El correo no sabe de firmas ni de rutas.
 *
 * @param  list<array{title: string, excerpt: string, url: string}>  $pieces
 */
class SnkKilkanaNewsletterMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $newspaper,
        public readonly string $issueSubject,
        public readonly ?string $preheader,
        public readonly array $pieces,
        public readonly ?string $openUrl,
        public readonly string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->issueSubject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'kilkana::mail.newsletter');
    }
}
