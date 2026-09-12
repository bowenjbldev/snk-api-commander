<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * Los estados por los que pasa un artículo, y la ÚNICA definición de cuáles son.
 *
 * De aquí sale todo: los valores de la columna en la migración, la regla `in:` de la API, el
 * muro de publicación, la acción masiva y las consultas. Escrita a mano en cada sitio, añadir
 * un estado obliga a encontrarlos todos, y olvidarse de uno no rompe nada de golpe: solo deja
 * de contemplarlo en silencio. Aquí, un estado nuevo es un `case` más.
 *
 * El ORDEN de los `case` es el del ciclo de vida, no alfabético, y no es cosmético: en una
 * columna ENUM ese es el orden con el que la base ordena, así que `ORDER BY status` recorre
 * la pieza de borrador a publicada.
 */
enum SnkKilkanaPostStatusEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** Se está escribiendo. Sin URL pública. */
    case Draft = 'draft';

    /**
     * Terminado y entregado, esperando a un editor.
     *
     * Existe porque sin él «borrador» significaría dos cosas incompatibles —«lo estoy
     * escribiendo» y «tomad, ya está»— y quien no puede publicar no tendría forma de decir
     * que ha acabado.
     */
    case Review = 'review';

    /**
     * Aprobado, saldrá en su `publish_at` — que en este estado es una fecha futura.
     *
     * No hay una columna aparte para la fecha prevista: `publish_at` es el instante de
     * publicación, y este estado dice que todavía no ha llegado.
     */
    case Scheduled = 'scheduled';

    /** Publicado. El único con `url_date`, y por tanto el único con URL pública. */
    case Published = 'published';

    /**
     * Si llegar a este estado exige el permiso `publish posts`.
     *
     * Es el muro entre escribir y publicar: quien firma no decide cuándo sale. Programar
     * cuenta como publicar —solo cambia el reloj—, así que los dos están dentro.
     */
    public function requiresPublishPermission(): bool
    {
        return $this === self::Scheduled || $this === self::Published;
    }

    /** Si el artículo es visible para un lector anónimo. */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /**
     * A qué estado mueve cada acción masiva de la barra de selección.
     *
     * Casi todas se llaman como su estado; la única irregular es `publish`, que es un verbo
     * porque en la barra lo son las cinco. `delete` no está: no mueve de estado, borra, y por
     * eso devuelve nulo en vez de un caso.
     *
     * Vive aquí y no en el controlador ni en el servicio porque los dos la necesitan —uno para
     * decidir si hace falta el permiso de publicación, el otro para escribir— y dos copias de
     * este mapa es como una acaba aceptando un estado que la otra no sabe escribir.
     */
    public static function fromBulkAction(string $action): ?self
    {
        return $action === 'publish' ? self::Published : self::tryFrom($action);
    }

    /**
     * Los estados que sacan la pieza a la calle, derivados del muro de arriba.
     *
     * Existen para lo que la validación no sabe preguntar de otra forma: un borrador nace sin
     * entradilla y sin fecha —el titular llega antes que el texto—, pero publicarlo así es
     * publicar media pieza. `required_if` necesita la lista, y sale de aquí para que añadir un
     * estado no obligue a acordarse de ampliarla.
     *
     * @return list<string>
     */
    public static function publishing(): array
    {
        return array_values(array_column(
            array_filter(self::cases(), fn (self $status) => $status->requiresPublishPermission()),
            'value',
        ));
    }
}
