<?php

namespace Packages\Snk\Kilkana\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Packages\Snk\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostRevisionModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostSlugModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Snk\Kilkana\Support\SnkKilkanaRichText;

class SnkKilkanaPostService
{
    public function query(int $userId, bool $canEditOthers): Builder
    {
        return $this->scopeToOwner(
            SnkKilkanaPostModel::query()
                // `engaged`, no `opens`: la columna de vistas del panel cuenta lecturas reales.
                ->withSum(['views as views_day' => fn ($query) => $query->where('date', now()->toDateString())], 'engaged')
                ->withSum(['views as views_week' => fn ($query) => $query->where('date', '>=', now()->subDays(6)->toDateString())], 'engaged')
                ->withSum('views as views_total', 'engaged'),
            $userId,
            $canEditOthers,
        );
    }

    public function find($id, int $userId, bool $canEditOthers): ?SnkKilkanaPostModel
    {
        $post = SnkKilkanaPostModel::query()->find($id);

        if ($post === null || $canEditOthers || (int) $post->created_by === $userId) {
            return $post;
        }

        return null;
    }

    /**
     * Guardar un articulo son VARIAS escrituras, y o entran todas o no entra ninguna.
     *
     * Un alta toca el post, puede dar de alta el medio de portada y da de alta las etiquetas
     * que no existan mas su pivote. Sin transaccion, un fallo a mitad deja el desecho puesto y
     * el cliente recibe un error: cree que no ha pasado nada y si ha pasado.
     */
    public function create(array $data, int $userId): SnkKilkanaPostModel
    {
        return DB::transaction(function () use ($data, $userId) {
            $post = SnkKilkanaPostModel::create([
                ...$this->columnsFrom($data, array_key_exists('image', $data)),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $this->syncRelations($post, $data);

            return $post->fresh();
        });
    }

    /**
     * Cinco escrituras: la revision, el post, las etiquetas y las dos del historial de URLs.
     *
     * Este es el que lo hizo evidente. Un fallo en las etiquetas dejaba el post guardado y la
     * revision registrada mientras la respuesta era un 500 — o sea, el historial anotaba una
     * edicion que se anuncio como fallida, y la URL vieja podia quedar sin su redireccion.
     */
    public function update(SnkKilkanaPostModel $post, array $data, int $userId, bool $imageSent): SnkKilkanaPostModel
    {
        return DB::transaction(function () use ($post, $data, $userId, $imageSent) {
            SnkKilkanaPostRevisionModel::create([
                'post_id' => $post->id,
                'user_id' => $userId,
                'payload' => $post->getAttributes(),
            ]);

            $previousPath = $post->url_path;
            $post->fill($this->columnsFrom($data, $imageSent));
            $post->updated_by = $userId;
            $post->save();
            $this->syncRelations($post, $data);
            $this->shiftUrlHistory($post, $previousPath);

            return $post->fresh();
        });
    }

    /** Una accion masiva es UNA decision de quien la pulsa: no puede aplicarse a medias. */
    public function bulk(array $ids, string $action, int $userId, bool $canEditOthers): int
    {
        return DB::transaction(function () use ($ids, $action, $userId, $canEditOthers) {
            $query = $this->scopeToOwner(SnkKilkanaPostModel::query(), $userId, $canEditOthers)
                ->whereIn('id', $ids);

            if ($action === 'delete') {
                $count = $query->count();
                $query->delete();

                return $count;
            }

            $count = 0;
            foreach ($query->get() as $post) {
                $post->status = $action === 'publish'
                    ? SnkKilkanaPostStatusEnum::Published
                    : SnkKilkanaPostStatusEnum::Draft;
                if ($action === 'publish' && ($post->publish_at === null || $post->publish_at->isFuture())) {
                    $post->publish_at = now();
                }
                $post->updated_by = $userId;
                $post->save();
                $count++;
            }

            return $count;
        });
    }

    /**
     * Publica lo programado cuya hora ya paso. Lo llama el scheduler, por tenant.
     *
     * Publicar es SOLO cambiar de estado: la fecha ya esta puesta y es la prevista. Cuando la
     * fecha prevista y la de publicacion eran dos columnas, aqui habia que copiar una en otra
     * y vaciar la primera, y ese traspaso llego a publicar piezas con la fecha en que se
     * redactaron —descolocando portada, sitemap y feed—. Sin dos columnas no hay traspaso que
     * equivocar.
     *
     * SIN transaccion, y a proposito: si una pieza falla, envolver el lote impediria salir a
     * las demas ese minuto. Publicar una a una hace que las buenas salgan y la rota lo vuelva
     * a intentar dentro de sesenta segundos, que es lo que un periodico quiere de un cron.
     */
    public function publishDue(): int
    {
        $due = SnkKilkanaPostModel::query()
            ->where('status', SnkKilkanaPostStatusEnum::Scheduled)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->get();

        foreach ($due as $post) {
            $post->update(['status' => SnkKilkanaPostStatusEnum::Published]);
        }

        return $due->count();
    }

    public function revisions(int $postId)
    {
        return SnkKilkanaPostRevisionModel::query()
            ->where('post_id', $postId)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get();
    }

    public function revision(int $postId, $revisionId): ?SnkKilkanaPostRevisionModel
    {
        return SnkKilkanaPostRevisionModel::query()
            ->whereKey($revisionId)
            ->where('post_id', $postId)
            ->first();
    }

    /** Restaurar tambien deja rastro antes de pisar: la instantanea y el post van juntas. */
    public function restore(
        SnkKilkanaPostModel $post,
        SnkKilkanaPostRevisionModel $revision,
        int $userId,
    ): SnkKilkanaPostModel {
        return DB::transaction(function () use ($post, $revision, $userId) {
            SnkKilkanaPostRevisionModel::create([
                'post_id' => $post->id,
                'user_id' => $userId,
                'payload' => $post->getAttributes(),
            ]);

            // Restaurar también mueve la URL: la versión recuperada trae su titular y por
            // tanto su slug. Sin esta contabilidad se PERDÍA la dirección que la pieza tuvo
            // entre el renombrado y la restauración —404 seco para quien la compartiera— y la
            // recuperada quedaba marcada como retirada, o sea redirigiendo hacia sí misma.
            $previousPath = $post->url_path;

            $columns = collect($revision->payload)
                ->except(['id', 'created_at', 'updated_at', 'created_by', 'updated_by'])
                ->all();
            $post->fill($columns);
            $post->updated_by = $userId;
            $post->save();
            $this->shiftUrlHistory($post, $previousPath);

            return $post->fresh();
        });
    }

    /**
     * La ruta que el artículo deja entra en el historial; la que toma sale de él.
     *
     * Lo hacen por igual el guardado y la restauración: las dos pueden mover la URL, y una
     * que se mueve sin dejar redirección es un enlace roto en cada sitio donde estuviera
     * compartida.
     *
     * El borrado va acotado **por artículo**: sin eso, adoptar una URL que otro había dejado
     * borraba la redirección de aquel y sus lectores acababan en esta pieza. Que el modelo ya
     * no deje adoptarla no quita el cinturón — si el dato viejo trae una fila así, esto no la
     * toca.
     */
    private function shiftUrlHistory(SnkKilkanaPostModel $post, ?string $previousPath): void
    {
        if ($previousPath && $post->url_path !== $previousPath) {
            SnkKilkanaPostSlugModel::updateOrCreate(['path' => $previousPath], ['post_id' => $post->id]);
        }

        if ($post->url_path) {
            SnkKilkanaPostSlugModel::query()
                ->where('path', $post->url_path)
                ->where('post_id', $post->id)
                ->delete();
        }
    }

    private function scopeToOwner(Builder $query, int $userId, bool $canEditOthers): Builder
    {
        return $canEditOthers ? $query : $query->where('created_by', $userId);
    }

    private function columnsFrom(array $data, bool $imageSent): array
    {
        $columns = collect($data)->except(['category', 'image', 'tags'])->all();

        if (array_key_exists('category', $data)) {
            $columns['category_id'] = SnkKilkanaCategoryModel::query()
                ->where('slug', $data['category'])
                ->value('id');
        }
        if ($imageSent) {
            $url = $data['image'] ?? null;
            $columns['cover_media_id'] = $url
                ? SnkKilkanaMediaModel::firstOrCreateByUrl($url)->id
                : null;
        }
        if (array_key_exists('body_blocks', $columns)) {
            $columns['body_blocks'] = SnkKilkanaRichText::cleanBlocks($columns['body_blocks']);
        }

        return $columns;
    }

    private function syncRelations(SnkKilkanaPostModel $post, array $data): void
    {
        if (! array_key_exists('tags', $data)) {
            return;
        }

        $ids = collect($data['tags'] ?? [])
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique()
            ->map(fn (string $name) => SnkKilkanaTagModel::firstOrCreateByName($name)->id);
        $post->tags()->sync($ids);
    }
}
