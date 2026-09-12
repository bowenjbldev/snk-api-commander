<?php

namespace Packages\Kilkana\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Un historial acotado: se guardan las N últimas de cada cosa y las viejas caen solas.
 *
 * Estas dos tablas son las que más crecen del esquema, y por una razón que no se ve en el
 * esquema: cada fila copia el objeto ENTERO —el cuerpo del artículo, la composición de la
 * portada—, y se escribe una por guardado. En la portada eso es una por autoguardado, que
 * salta cada 800 ms de pausa: una sesión componiendo deja decenas de copias de 4 KB.
 *
 * Se poda al insertar, dentro de la misma transacción, y no con un cron: así la tabla está
 * acotada POR CONSTRUCCIÓN y nadie tiene que acordarse de barrer. El coste es una consulta
 * por guardado sobre un índice que ya existe.
 *
 * Lo que esto NO resuelve, y conviene tener claro: el tope acota el espacio, no el ruido. Una
 * ráfaga de autoguardados sigue gastando el historial entero en una sola sesión, así que
 * «deshacer a lo de ayer» puede no existir aunque el número parezca generoso. Agrupar una
 * ráfaga en una sola revisión es otra decisión, y va aparte.
 */
trait SnkKilkanaPrunesRevisionsSupport
{
    /** La columna que agrupa las revisiones de una misma cosa. */
    abstract public static function revisionOwnerKey(): string;

    /** Cuántas se conservan por dueño. Un modelo puede subirlo o bajarlo. */
    public static function revisionsKept(): int
    {
        return 50;
    }

    protected static function bootSnkKilkanaPrunesRevisionsSupport(): void
    {
        static::created(function (Model $revision) {
            $key = static::revisionOwnerKey();

            // Por `id` y no por `created_at`: dos guardados del mismo segundo empatan en la
            // fecha, y un empate ahí decide mal cuál se tira.
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
