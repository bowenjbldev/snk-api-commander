<?php

namespace Packages\Kilkana\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Packages\Kilkana\Services\SnkKilkanaAccountLinkService;

/**
 * El correo con el enlace para poner contraseña: invitación a la redacción o recuperación.
 *
 * Un solo correo con dos textos y no dos correos: el enlace es el mismo, lo que cambia es si
 * quien lo recibe sabe que tiene cuenta. Y dice cuánto dura, porque la primera reacción a un
 * enlace caducado es pensar que el panel está roto.
 */
class SnkKilkanaAccountLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $kind,
        public readonly string $newspaper,
        public readonly string $url,
        public readonly int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->kind === SnkKilkanaAccountLinkService::INVITE
            ? "You have a seat at {$this->newspaper}"
            : "Reset your password for {$this->newspaper}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'kilkana::mail.account-link');
    }
}
