<?php

namespace Packages\Kilkana\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Packages\Kilkana\Models\SnkKilkanaMediaCollectionModel;
use Packages\Kilkana\Models\SnkKilkanaMediaTagModel;

/**
 * El vocabulario de la biblioteca: etiquetas y colecciones de medios.
 *
 * Dos tablas de la misma forma tras un solo juego de rutas, donde el segmento decide cuál.
 * No se parecen a las etiquetas de artículo aunque lo aparenten: aquellas nacen al vuelo
 * tecleándolas, estas se crean a propósito. Es un vocabulario controlado, así que una
 * etiqueta sin fotos es legítima —la creaste para usarla— y no residuo que haya que barrer.
 */
class SnkKilkanaMediaTaxonomyService
{
    /** @return class-string<Model> */
    public function modelFor(string $taxonomy): string
    {
        return $taxonomy === 'tags'
            ? SnkKilkanaMediaTagModel::class
            : SnkKilkanaMediaCollectionModel::class;
    }

    /**
     * El vocabulario entero, con cuántos medios cuelgan de cada término.
     *
     * El recuento no es adorno: borrar un término se lleva sus asociaciones por CASCADE y sin
     * preguntar, así que quien lo borre tiene que poder ver ANTES a cuántas fotos afecta.
     */
    public function all(string $taxonomy)
    {
        return $this->withCounts($taxonomy)->orderBy('name')->get();
    }

    /**
     * Las tres cifras que el panel lee de un término sin abrirlo.
     *
     * Cuántas fotos lleva, cuántas de ellas bloquean un despliegue por no tener alt, y cuándo
     * entró la última. Las cuenta el servidor porque el archivo está en el servidor: el panel
     * solo tiene la página que enseña, y contarlas allí daría una columna vacía.
     *
     * @return Builder<Model>
     */
    private function withCounts(string $taxonomy)
    {
        $model = $this->modelFor($taxonomy);

        return $model::query()
            ->withCount('media')
            ->withCount(['media as missing_alt_count' => fn ($query) => $query
                ->where(fn ($sub) => $sub->whereNull('alt')->orWhere('alt', ''))])
            ->withMax('media', 'created_at');
    }

    public function find(string $taxonomy, $id): ?Model
    {
        return $this->withCounts($taxonomy)->find($id);
    }

    public function table(string $taxonomy): string
    {
        $model = $this->modelFor($taxonomy);

        return (new $model)->getTable();
    }

    public function create(string $taxonomy, array $data): Model
    {
        $model = $this->modelFor($taxonomy);

        return $model::create($data);
    }

    public function rename(Model $row, array $data): Model
    {
        $row->update($data);

        return $row->loadCount('media');
    }

    /**
     * Vuelca un término en otro y borra el vaciado.
     *
     * Es una sola acción y no «mover y luego borrar»: entre las dos mitades, un fallo dejaría
     * dos estantes con las mismas fotos y nadie sabría cuál es el bueno. `syncWithoutDetaching`
     * y no `sync` porque el destino ya tiene lo suyo, y una foto que estuviera en los dos no
     * puede entrar dos veces en el pivote.
     *
     * @return int cuántas fotos han cambiado de sitio
     */
    public function merge(Model $source, Model $target): int
    {
        $mediaIds = $source->media()->pluck('kilkana_media.id')->all();

        DB::transaction(function () use ($source, $target, $mediaIds) {
            $target->media()->syncWithoutDetaching($mediaIds);
            $source->delete();
        });

        return count($mediaIds);
    }

    /** Devuelve cuántas asociaciones se ha llevado por delante, para poder decirlo. */
    public function remove(Model $row): int
    {
        $attached = $row->media()->count();
        $row->delete();

        return $attached;
    }
}
