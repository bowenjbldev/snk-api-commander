<?php

namespace Packages\Kilkana\Services;

use Illuminate\Database\Eloquent\Builder;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;

/**
 * La papelera: una cuenta atrás, no un archivo.
 *
 * Lo borrado se queda treinta días con su URL reservada y luego se purga solo. Dos clases
 * entran —piezas y medios—, y lo que decide sobre cada una no es qué era, sino **qué sigue
 * apuntando a ella**: purgar un medio que una pieza publicada lleva de portada la deja con
 * una imagen rota, y a los treinta días pasa solo.
 */
class SnkKilkanaTrashService
{
    public const RETENTION_DAYS = 30;

    public const KINDS = ['post', 'media'];

    public function __construct(
        private readonly SnkKilkanaMediaService $media,
        private readonly SnkKilkanaActivityService $registro,
    ) {}

    /**
     * Las filas de la papelera, de lo último que cayó a lo que lleva más tiempo.
     *
     * @param  list<string>  $kinds
     * @return list<array<string, mixed>>
     */
    public function rows(array $kinds): array
    {
        $filas = [];
        if (in_array('post', $kinds, true)) {
            $posts = SnkKilkanaPostModel::onlyTrashed()
                ->withSum('views as reads', 'engaged')
                ->orderByDesc('deleted_at')
                ->get();
            foreach ($posts as $post) {
                $filas[] = [
                    ...$this->common('post', $post->getKey(), $post->title, $post->deleted_at, $post->deleted_by),
                    'path' => $post->url_path ? '/'.$post->url_path : null,
                    'reach' => (int) $post->reads,
                    'excerpt' => $post->excerpt,
                    'refs' => [],
                ];
            }
        }
        if (in_array('media', $kinds, true)) {
            $medios = SnkKilkanaMediaModel::onlyTrashed()->orderByDesc('deleted_at')->get();
            foreach ($medios as $medio) {
                $filas[] = [
                    // Sin título ni nombre va vacío: el rótulo lo pone el panel en su idioma.
                    ...$this->common('media', $medio->getKey(), $medio->title ?: ($medio->original_name ?: ''), $medio->deleted_at, $medio->deleted_by),
                    'path' => $medio->width && $medio->height ? "{$medio->width} × {$medio->height}" : null,
                    'reach' => (int) $medio->size_bytes,
                    'excerpt' => null,
                    'refs' => $this->mediaRefs($medio),
                ];
            }
        }
        usort($filas, fn (array $a, array $b) => strcmp($b['deleted_at'], $a['deleted_at']));

        return $this->withNames($filas);
    }

    /** Devuelve una pieza como borrador, o un medio a la biblioteca. `false` si no estaba en la papelera. */
    public function restore(string $kind, int $id): bool
    {
        if ($kind === 'post') {
            $post = SnkKilkanaPostModel::onlyTrashed()->find($id);
            if ($post === null) {
                return false;
            }
            // Como borrador: no vuelve publicada a su URL sin que nadie la mire. La URL sigue
            // sellada, así que al republicarla sale por la misma.
            $this->registro->quiet(function () use ($post) {
                if ($post->status->requiresPublishPermission()) {
                    $post->forceFill(['status' => SnkKilkanaPostStatusEnum::Draft])->save();
                }
                $post->forceFill(['deleted_by' => null])->restore();
            });
            $this->registro->log('restore', 'restored_from_trash', $post->title, '/posts');

            return true;
        }

        $medio = SnkKilkanaMediaModel::onlyTrashed()->find($id);
        if ($medio === null) {
            return false;
        }
        $this->registro->quiet(fn () => $medio->forceFill(['deleted_by' => null])->restore());
        $this->registro->log('restore', 'restored_from_trash', $medio->title ?: ($medio->original_name ?: ''), '/media');

        return true;
    }

    /** Se va de inmediato, sin esperar los treinta días. `false` si no estaba en la papelera. */
    public function purge(string $kind, int $id): bool
    {
        if ($kind === 'post') {
            $post = SnkKilkanaPostModel::onlyTrashed()->find($id);
            if ($post === null) {
                return false;
            }
            $post->forceDelete();

            return true;
        }

        $medio = SnkKilkanaMediaModel::onlyTrashed()->find($id);
        if ($medio === null) {
            return false;
        }
        $this->media->forceDestroy($medio);

        return true;
    }

    /**
     * Vacía la papelera de esas clases. Purga, no restaura: son operaciones opuestas.
     *
     * @param  list<string>  $kinds
     */
    public function empty(array $kinds): int
    {
        $total = 0;
        foreach ($kinds as $kind) {
            $query = $kind === 'post' ? SnkKilkanaPostModel::onlyTrashed() : SnkKilkanaMediaModel::onlyTrashed();
            foreach ($query->get() as $fila) {
                $total += (int) $this->purge($kind, (int) $fila->getKey());
            }
        }

        return $total;
    }

    /** Lo que lleva más de treinta días: se purga solo. Devuelve cuántas cosas se fueron. */
    public function purgeExpired(): int
    {
        $limite = now()->subDays(self::RETENTION_DAYS);
        $total = 0;
        foreach (SnkKilkanaPostModel::onlyTrashed()->where('deleted_at', '<', $limite)->get() as $post) {
            $total += (int) $this->purge('post', (int) $post->getKey());
        }
        foreach (SnkKilkanaMediaModel::onlyTrashed()->where('deleted_at', '<', $limite)->get() as $medio) {
            $total += (int) $this->purge('media', (int) $medio->getKey());
        }

        return $total;
    }

    /**
     * Cuántas cosas hay en la papelera, para el raíl.
     *
     * @param  list<string>  $kinds
     */
    /**
     * Qué clases puede ver quien pregunta: cada una entra con el permiso de borrarla. Vive
     * aquí porque la preguntan dos puertas —la pantalla de la papelera y el raíl del panel— y
     * es la regla que decide si alguien ve algo o recibe un 403.
     *
     * @return list<string>
     */
    public function kindsFor(?CoreUserModel $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            self::KINDS,
            fn (string $kind) => $user->can("delete {$kind}".($kind === 'post' ? 's' : '')),
        ));
    }

    public function count(array $kinds): int
    {
        $n = 0;
        if (in_array('post', $kinds, true)) {
            $n += SnkKilkanaPostModel::onlyTrashed()->count();
        }
        if (in_array('media', $kinds, true)) {
            $n += SnkKilkanaMediaModel::onlyTrashed()->count();
        }

        return $n;
    }

    /** @return array<string, mixed> */
    private function common(string $kind, int $id, string $title, $deletedAt, ?int $deletedBy): array
    {
        $purga = $deletedAt->copy()->addDays(self::RETENTION_DAYS);

        return [
            'id' => (string) $id,
            'kind' => $kind,
            'title' => $title,
            'deleted_by_id' => $deletedBy,
            'deleted_at' => $deletedAt->toIso8601String(),
            'purges_at' => $purga->toIso8601String(),
            'days_left' => max(0, (int) ceil(now()->diffInSeconds($purga, false) / 86400)),
        ];
    }

    /**
     * Las piezas que siguen apuntando a un medio: de portada, de imagen social o dentro del
     * cuerpo. Es el dato que decide, y cuenta **todas**, no solo las publicadas: un borrador
     * pierde la foto igual al purgar, y delante de algo irreversible quedarse corto es el lado
     * malo por el que equivocarse.
     *
     * Los tres `LIKE` van anclados —`,`, `}` o comillas cierran el número—: sin el cierre, el
     * id 1 casaría con `"media_id":12` y `"media_id":100`, y un aviso que exagera no lo
     * sospecha nadie.
     *
     * @return list<string>
     */
    private function mediaRefs(SnkKilkanaMediaModel $medio): array
    {
        $id = (int) $medio->getKey();

        return SnkKilkanaPostModel::query()
            ->where(fn (Builder $q) => $q
                ->where('cover_media_id', $id)
                ->orWhere('og_image_id', $id)
                ->orWhere('body_blocks', 'like', '%"media_id":'.$id.',%')
                ->orWhere('body_blocks', 'like', '%"media_id":'.$id.'}%')
                ->orWhere('body_blocks', 'like', '%"media_id":"'.$id.'"%'))
            ->orderByDesc('publish_at')
            ->pluck('title')
            ->all();
    }

    /**
     * Quién lo tiró, por nombre y en una consulta para la lista entera.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return list<array<string, mixed>>
     */
    private function withNames(array $filas): array
    {
        $ids = array_values(array_unique(array_filter(array_column($filas, 'deleted_by_id'))));
        $nombres = $ids === [] ? collect() : CoreUserModel::query()->whereKey($ids)->get(['id', 'name', 'full_name'])
            ->mapWithKeys(fn (CoreUserModel $u) => [$u->getKey() => $u->full_name ?: $u->name]);

        return array_map(function (array $fila) use ($nombres) {
            $fila['who'] = $fila['deleted_by_id'] === null ? null : $nombres->get($fila['deleted_by_id']);
            unset($fila['deleted_by_id']);

            return $fila;
        }, $filas);
    }
}
