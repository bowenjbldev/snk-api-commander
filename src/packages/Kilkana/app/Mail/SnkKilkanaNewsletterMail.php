<?php

namespace Packages\Kilkana\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Un número del boletín en la bandeja de un suscriptor.
 *
 * Todo lo que lleva viene resuelto: las piezas con su enlace ya contado, el píxel de apertura
 * y la baja firmada, cada uno para ESTE suscriptor. El correo no sabe de firmas ni de rutas.
 *
 * @param  list<array{title: string, excerpt: string, url: string}>  $posts
 */
class SnkKilkanaNewsletterMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $newspaper,
        public readonly string $issueSubject,
        public readonly ?string $preheader,
        public readonly array $posts,
        public readonly ?string $openUrl,
        public readonly string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        // La dirección es la de la instalación —un remitente propio pide un dominio verificado—,
        // pero el nombre es el del periódico: es lo que el lector ve en su bandeja.
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->newspaper),
            subject: $this->issueSubject,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'kilkana::mail.newsletter');
    }
}
