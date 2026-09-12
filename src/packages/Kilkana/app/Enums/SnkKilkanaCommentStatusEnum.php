<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * Por dónde pasa un comentario, y la ÚNICA definición de los cuatro estados.
 *
 * Es la tabla que recibe escritura de gente SIN CUENTA, así que aquí la lista cerrada importa
 * más que en ninguna otra: la leen la regla del controlador, el mapeo de la acción masiva y el
 * servicio, y copiada a mano en cada uno sobre una columna abierta acaba aceptando cualquier
 * cadena.
 *
 * Orden de ciclo de vida: todo entra por `pending` y de ahí sale hacia un lado o hacia otro.
 */
enum SnkKilkanaCommentStatusEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** Recién llegado. No se ve en el artículo hasta que alguien lo mire. */
    case Pending = 'pending';

    /** Publicado bajo el artículo. */
    case Approved = 'approved';

    /**
     * Leído y descartado: no es basura, es un comentario que no sale. Se guarda porque es el
     * historial de la cuenta —dos rechazos son un mal día, seis son una cuenta vigilada— y
     * porque rechazado y spam son desenlaces distintos que la moderación necesita distinguir.
     */
    case Rejected = 'rejected';

    /** Basura. Se guarda en vez de borrarse: sirve para reconocer la siguiente. */
    case Spam = 'spam';

    /** Si lo ve un lector. */
    public function isPublic(): bool
    {
        return $this === self::Approved;
    }
}
