<?php

namespace Packages\Snk\Kilkana\Enums;

use Packages\Snk\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * Por donde pasa un comentario, y la UNICA definicion de los tres estados.
 *
 * Es la tabla que recibe escritura de gente SIN CUENTA, asi que aqui la lista cerrada importa
 * mas que en ninguna otra: los tres valores vivian escritos a mano en la regla del
 * controlador, en el mapeo de la accion masiva y dos veces en el servicio, sobre una columna
 * que aceptaba cualquier cadena.
 *
 * Orden de ciclo de vida: todo entra por `pending` y de ahi sale hacia un lado o hacia otro.
 */
enum SnkKilkanaCommentStatusEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** Recien llegado. No se ve en el articulo hasta que alguien lo mire. */
    case Pending = 'pending';

    /** Publicado bajo el articulo. */
    case Approved = 'approved';

    /** Basura. Se guarda en vez de borrarse: sirve para reconocer la siguiente. */
    case Spam = 'spam';

    /** Si lo ve un lector. */
    public function isPublic(): bool
    {
        return $this === self::Approved;
    }
}
