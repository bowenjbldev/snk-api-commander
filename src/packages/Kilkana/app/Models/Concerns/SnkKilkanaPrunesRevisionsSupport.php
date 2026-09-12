<?php

namespace Packages\Kilkana\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Un historial acotado: se guardan las N ultimas de cada cosa y las viejas caen solas.
 *
 * Estas dos tablas son las que mas crecen del esquema, y por una razon que no se ve en el
 * esquema: cada fila copia el objeto ENTERO —el cuerpo del articulo, la composicion de la
 * portada—, y se escribe una por guardado. En la portada eso es una por autoguardado, que
 * salta cada 800 ms de pausa: una sesion componiendo deja decenas de copias de 4 KB.
 *
 * Se poda al insertar, dentro de la misma transaccion, y no con un cron: asi la tabla esta
 * acotada POR CONSTRUCCION y nadie tiene que acordarse de barrer. El coste es una consulta
 * por guardado sobre un indice que ya existe.
 *
 * Lo que esto NO resuelve, y conviene tener claro: el tope acota el espacio, no el ruido. Una
 * rafaga de autoguardados sigue gastando el historial entero en una sola sesion, asi que
 * «deshacer a lo de ayer» puede no existir aunque el numero parezca generoso. Agrupar una
 * rafaga en una sola revision es otra decision, y va aparte.
 */
trait SnkKilkanaPrunesRevisionsSupport
{
    /** La columna que agrupa las revisiones de una misma cosa. */
    abstract public static function revisionOwnerKey(): string;

    /** Cuantas se conservan por dueño. Un modelo puede subirlo o bajarlo. */
    public static function revisionsKept(): int
    {
        return 50;
    }

    protected static function bootSnkKilkanaPrunesRevisionsSupport(): void
    {
        static::created(function (Model $revision) {
            $key = static::revisionOwnerKey();

            // Por `id` y no por `created_at`: dos guardados del mismo segundo empatan en la
            // fecha, y un empate ahi decide mal cual se tira.
            $surplus = static::query()
                ->where($key, $revision->{$key})
                ->orderByDesc('id')
                ->offset(static::revisionsKept())
                ->limit(PHP_INT_MAX)
                ->pluck('id');

            if ($surplus->isNotEmpty()) {
                static::query()->whereIn('id', $surplus)->delete();
            }
        });
    }
}
