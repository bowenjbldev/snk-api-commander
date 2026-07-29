<?php

namespace Packages\Snk\Kilkana\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El correo que convierte una direccion en un suscriptor.
 *
 * Doble opt-in, y no por tramite: el clic desde el buzon es lo unico que demuestra que la
 * direccion es de quien la escribio. Sin el, cualquiera apunta a cualquiera —y quien recibe
 * un boletin que no pidio es justo quien reclama.
 *
 * Lleva TAMBIEN el enlace de baja, aunque todavia no este suscrito del todo. Lo exige el RGPD
 * (art. 7.3: retirar el consentimiento tan facil como darlo) y de paso es lo que un cliente de
 * correo espera encontrar para no tratar el mensaje como basura.
 *
 * Las dos URLs llegan ya firmadas y con el host del periodico: construirlas aqui las ataria a
 * `APP_URL`, que apunta a la API y no al tenant.
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
