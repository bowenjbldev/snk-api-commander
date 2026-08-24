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
        return $this->conCuentas($taxonomy)->orderBy('name')->get();
    }

    /**
     * Las tres cifras que el panel lee de un termino sin abrirlo.
     *
     * Cuantas fotos lleva, cuantas de ellas bloquean un despliegue por no tener alt, y cuando
     * entro la ultima. Las tres se calculaban recorriendo la biblioteca entera en el cliente,
     * que es justo lo que deja de estar ahi: con el archivo en el servidor, o las cuenta el
     * servidor o el panel ensena una columna vacia.
     *
     * @return Builder<Model>
     */
    private function conCuentas(string $taxonomy)
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
        return $this->conCuentas($taxonomy)->find($id);
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
     * Vuelca un termino en otro y borra el vaciado.
     *
     * Es una sola accion y no «mover y luego borrar»: entre las dos mitades, un fallo dejaria
     * dos estantes con las mismas fotos y nadie sabria cual era el bueno. `syncWithoutDetaching`
     * y no `sync` porque el destino ya tiene lo suyo, y una foto que estuviera en los dos no
     * puede entrar dos veces en el pivote.
     *
     * @return int cuantas fotos han cambiado de sitio
     */
    public function merge(Model $desde, Model $hasta): int
    {
        $fotos = $desde->media()->pluck('kilkana_media.id')->all();

        DB::transaction(function () use ($desde, $hasta, $fotos) {
            $hasta->media()->syncWithoutDetaching($fotos);
            $desde->delete();
        });

        return count($fotos);
    }

    /** Devuelve cuantas asociaciones se ha llevado por delante, para poder decirlo. */
    public function remove(Model $row): int
    {
        $asociaciones = $row->media()->count();
        $row->delete();

        return $asociaciones;
    }
}
