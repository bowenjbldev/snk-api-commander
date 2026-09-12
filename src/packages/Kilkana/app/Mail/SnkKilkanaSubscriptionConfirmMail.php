<?php

namespace Packages\Kilkana\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El correo que convierte una dirección en un suscriptor.
 *
 * Doble opt-in, y no por trámite: el clic desde el buzón es lo único que demuestra que la
 * dirección es de quien la escribió. Sin él, cualquiera apunta a cualquiera —y quien recibe
 * un boletín que no pidió es justo quien reclama.
 *
 * Lleva TAMBIÉN el enlace de baja, aunque todavía no esté suscrito del todo. Lo exige el RGPD
 * (art. 7.3: retirar el consentimiento tan fácil como darlo) y de paso es lo que un cliente de
 * correo espera encontrar para no tratar el mensaje como basura.
 *
 * Las dos URLs llegan ya firmadas y con el host del periódico: construirlas aquí las ataría a
 * `APP_URL`, que apunta a la API y no al inquilino.
 */
class SnkKilkanaSubscriptionConfirmMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $newspaper,
        public readonly string $confirmUrl,
        public readonly string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Confirm your subscription to {$this->newspaper}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'kilkana::mail.subscription-confirm');
    }
}
