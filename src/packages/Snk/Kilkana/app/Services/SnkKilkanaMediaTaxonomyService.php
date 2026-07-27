<?php

namespace Packages\Snk\Kilkana\Services;

use Illuminate\Database\Eloquent\Model;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaCollectionModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaTagModel;

/**
 * El vocabulario de la biblioteca: etiquetas y colecciones de medios.
 *
 * Dos tablas de la misma forma tras un solo juego de rutas, donde el segmento decide cual.
 * No se parecen a las etiquetas de articulo aunque lo aparenten: aquellas nacen al vuelo
 * tecleandolas, estas se crean a proposito. Es un vocabulario controlado, asi que una
 * etiqueta sin fotos es legitima —la creaste para usarla— y no residuo que haya que barrer.
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
     * El vocabulario entero, con cuantos medios cuelgan de cada termino.
     *
     * El recuento no es adorno: borrar un termino se lleva sus asociaciones por CASCADE y sin
     * preguntar, asi que quien lo borre tiene que poder ver ANTES a cuantas fotos afecta.
     */
    public function all(string $taxonomy)
    {
        $model = $this->modelFor($taxonomy);

        return $model::query()->withCount('media')->orderBy('name')->get();
    }

    public function find(string $taxonomy, $id): ?Model
    {
        $model = $this->modelFor($taxonomy);

        return $model::query()->withCount('media')->find($id);
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

    /** Devuelve cuantas asociaciones se ha llevado por delante, para poder decirlo. */
    public function remove(Model $row): int
    {
        $asociaciones = $row->media()->count();
        $row->delete();

        return $asociaciones;
    }
}
