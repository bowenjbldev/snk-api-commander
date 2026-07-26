<?php

namespace Packages\Snk\Kilkana\Enums;

/**
 * Los estados por los que pasa un articulo, y la UNICA definicion de cuales son.
 *
 * La lista vivia escrita a mano en cinco sitios —dos reglas de validacion, el muro de
 * publicacion, la accion masiva y cada consulta publica— y la columna era un `varchar(16)`
 * que aceptaba cualquier cosa. Anadir un estado obligaba a encontrar los cinco, y olvidarse
 * de uno no rompia nada de golpe: solo dejaba de contemplarlo en silencio.
 *
 * Ahora sale de aqui todo: los valores de la columna en la migracion, la regla `in:` de la
 * API y las consultas. Un estado nuevo es un `case` mas.
 *
 * El ORDEN de los `case` es el del ciclo de vida, no alfabetico, y no es cosmetico: en una
 * columna ENUM ese es el orden con el que la base ordena, asi que `ORDER BY status` recorre
 * la pieza de borrador a publicada.
 */
enum SnkKilkanaPostStatusEnum: string
{
    /** Se esta escribiendo. Sin URL publica. */
    case Draft = 'draft';

    /**
     * Terminado y entregado, esperando a un editor.
     *
     * Existe porque sin el «borrador» significaba dos cosas incompatibles —«lo estoy
     * escribiendo» y «tomad, ya esta»— y quien no puede publicar no tenia forma de decir
     * que habia acabado.
     */
    case Review = 'review';

    /**
     * Aprobado, saldra en su `publish_at` — que en este estado es una fecha futura.
     *
     * No hay una columna aparte para la fecha prevista: `publish_at` es el instante de
     * publicacion, y este estado dice que todavia no ha llegado.
     */
    case Scheduled = 'scheduled';

    /** Publicado. El unico con `url_date`, y por tanto el unico con URL publica. */
    case Published = 'published';

    /**
     * Los valores en crudo. Los consume la migracion para declarar la columna, de modo que
     * la lista de la base y la del codigo no pueden discrepar.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Regla de validacion de la API, derivada: `in:draft,review,scheduled,published`. */
    public static function rule(): string
    {
        return 'in:'.implode(',', self::values());
    }

    /**
     * Si llegar a este estado exige el permiso `publish posts`.
     *
     * Es el muro entre escribir y publicar: quien firma no decide cuando sale. Programar
     * cuenta como publicar —solo cambia el reloj—, asi que los dos estan dentro.
     */
    public function requiresPublishPermission(): bool
    {
        return $this === self::Scheduled || $this === self::Published;
    }

    /** Si el articulo es visible para un lector anonimo. */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }
}
