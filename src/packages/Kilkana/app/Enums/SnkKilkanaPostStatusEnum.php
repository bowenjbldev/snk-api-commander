<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

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
    use SnkKilkanaEnumValuesSupport;

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

    /**
     * A que estado mueve cada accion masiva de la barra de seleccion.
     *
     * Casi todas se llaman como su estado; la unica irregular es `publish`, que es un verbo
     * porque en la barra lo son las cinco. `delete` no esta: no mueve de estado, borra, y por
     * eso devuelve nulo en vez de un caso.
     *
     * Vive aqui y no en el controlador ni en el servicio porque los dos la necesitan —uno para
     * decidir si hace falta el permiso de publicacion, el otro para escribir— y dos copias de
     * este mapa es como una acaba aceptando un estado que la otra no sabe escribir.
     */
    public static function fromBulkAction(string $action): ?self
    {
        return $action === 'publish' ? self::Published : self::tryFrom($action);
    }

    /**
     * Los estados que sacan la pieza a la calle, derivados del muro de arriba.
     *
     * Existen para lo que la validacion no sabe preguntar de otra forma: un borrador nace sin
     * entradilla y sin fecha —el titular llega antes que el texto—, pero publicarlo asi es
     * publicar media pieza. `required_if` necesita la lista, y sale de aqui para que anadir un
     * estado no obligue a acordarse de ampliarla.
     *
     * @return list<string>
     */
    public static function publishing(): array
    {
        return array_values(array_column(
            array_filter(self::cases(), fn (self $estado) => $estado->requiresPublishPermission()),
            'value',
        ));
    }
}
