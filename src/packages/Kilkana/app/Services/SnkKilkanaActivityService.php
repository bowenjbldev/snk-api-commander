<?php

namespace Packages\Kilkana\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Kilkana\Http\Resources\SnkKilkanaActivityResource;
use Packages\Kilkana\Models\SnkKilkanaActivityModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaLayoutModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Models\SnkKilkanaThemeModel;

/**
 * El registro de la redacción, y el único sitio que escribe en él.
 *
 * Casi todo lo apunta solo, escuchando a los modelos: una pieza que cambia de estado, una
 * sección que cambia de nombre, un tema que se viste. Lo que un modelo no puede contar por sí
 * mismo —un lote de comentarios, una invitación con su papel, una fusión de etiquetas— lo
 * apunta quien lo hace con `log()`. Quién firma sale de la sesión; sin sesión firma la máquina.
 *
 * Nunca falla hacia fuera: un registro que tumba la escritura que registra es peor que un
 * hueco en el registro.
 */
class SnkKilkanaActivityService
{
    /** Mientras es verdad, los modelos no cuentan nada: lo cuenta quien los mueve, o nadie. */
    private bool $callado = false;

    /** Techo del adelanto: `count` lo pide quien llama y no puede pedir el registro entero. */
    public const COUNT_MAX = 500;

    /**
     * Lo que puede ver quien pregunta. Los movimientos de cuentas —invitar, suspender— son
     * de quien administra cuentas: el resto de la redacción no ve quién entró ni quién salió.
     */
    public function visible(): Builder
    {
        $query = SnkKilkanaActivityModel::query()->recent();
        if (! auth()->user()?->can('index users')) {
            $query->where('kind', '<>', 'seat');
        }

        return $query;
    }

    /**
     * El adelanto de la campana: solo filas y hasta cuándo miró esta cuenta.
     *
     * La gente y las cifras de la pantalla del registro son cinco consultas más que el armazón
     * pagaría en cada carga para no pintarlas. `$desde` es lo ya filtrado cuando quien llama
     * tiene pestaña o persona; sin él, todo lo visible.
     *
     * @return array{data: Collection<int, array<string, mixed>>, seen_at: ?string}
     */
    public function bell(int $count, ?Builder $desde = null): array
    {
        // Del array crudo y no por su nombre: una cuenta recién creada no lleva sus columnas
        // anulables, y `actingAs()` conserva ese modelo en memoria en vez de releerlo.
        $visto = auth()->user()?->getAttributes()['activity_seen_at'] ?? null;

        return [
            'data' => ($desde ?? $this->visible())->limit(min($count, self::COUNT_MAX))->get()->pipe(SnkKilkanaActivityResource::collection(...)),
            // Hasta cuándo miró esta cuenta: lo posterior es lo que la campana marca.
            'seen_at' => $visto ? Carbon::parse($visto)->toIso8601String() : null,
        ];
    }

    /**
     * Corre algo sin que los modelos lo cuenten. Para lo que ya se apunta con su propio verbo
     * —fundir dos etiquetas borra una, restaurar una versión guarda la pieza— y para sembrar.
     */
    public function quiet(callable $trabajo): mixed
    {
        $antes = $this->callado;
        $this->callado = true;
        try {
            return $trabajo();
        } finally {
            $this->callado = $antes;
        }
    }

    /**
     * Apunta un movimiento.
     *
     * @param  array{before?: ?string, after?: ?string, user?: ?CoreUserModel, force?: bool}  $extra
     */
    public function log(string $kind, string $verb, string $what, string $href, array $extra = []): void
    {
        if ($this->callado && ! ($extra['force'] ?? false)) {
            return;
        }
        try {
            $actor = array_key_exists('user', $extra) ? $extra['user'] : auth()->user();
            // Del array crudo: quien actúa puede llegar como un modelo que no trajo todas sus
            // columnas —una sesión de prueba conserva el que se creó, y una consulta recortada
            // no selecciona lo que no pinta—. Pedirlas por su nombre lanzaba, el `catch` de
            // abajo se lo tragaba con un `report()`, y la línea del registro **desaparecía sin
            // que nada fallara**: justo lo que la auditoría no puede hacer.
            $suyo = $actor?->getAttributes() ?? [];

            SnkKilkanaActivityModel::create([
                'user_id' => $actor?->getKey(),
                'actor_name' => $actor === null
                    ? SnkKilkanaActivityModel::SYSTEM
                    : (($suyo['full_name'] ?? null) ?: ($suyo['name'] ?? SnkKilkanaActivityModel::SYSTEM)),
                'actor_role' => $actor?->roles->first()?->name,
                'kind' => $kind,
                'verb' => $verb,
                'what' => mb_substr($what, 0, 500),
                'before' => isset($extra['before']) ? mb_substr($extra['before'], 0, 255) : null,
                'after' => isset($extra['after']) ? mb_substr($extra['after'], 0, 255) : null,
                'href' => $href,
                'created_at' => now(),
            ]);
        } catch (\Throwable $fallo) {
            report($fallo);
        }
    }

    /** Engancha los modelos que se cuentan solos. Se llama una vez, desde el proveedor. */
    public function observe(): void
    {
        SnkKilkanaPostModel::created(fn (SnkKilkanaPostModel $p) => $this->post($p, true));
        SnkKilkanaPostModel::updated(fn (SnkKilkanaPostModel $p) => $this->post($p, false));
        SnkKilkanaPostModel::deleted(fn (SnkKilkanaPostModel $p) => $this->log('delete', $p->isForceDeleting() ? 'deleted for good' : 'deleted', $p->title, $p->isForceDeleting() ? '/posts' : '/trash'));
        SnkKilkanaPostModel::restored(fn (SnkKilkanaPostModel $p) => $this->log('restore', 'restored', $p->title, '/posts'));

        SnkKilkanaCategoryModel::created(fn (SnkKilkanaCategoryModel $c) => $this->log('category', 'created the category', $c->name, '/categories'));
        SnkKilkanaCategoryModel::updated(function (SnkKilkanaCategoryModel $c) {
            if ($c->wasChanged('name')) {
                $this->log('category', 'renamed the category', "{$c->getOriginal('name')} → {$c->name}", '/categories', ['before' => $c->getOriginal('name'), 'after' => $c->name]);
            }
        });
        SnkKilkanaCategoryModel::deleted(fn (SnkKilkanaCategoryModel $c) => $this->log('category', 'deleted the category', $c->name, '/categories'));

        SnkKilkanaTagModel::created(fn (SnkKilkanaTagModel $t) => $this->log('tag', 'created the tag', $t->name, '/tags'));
        SnkKilkanaTagModel::deleted(fn (SnkKilkanaTagModel $t) => $this->log('tag', 'deleted the tag', $t->name, '/tags'));

        SnkKilkanaMediaModel::created(fn (SnkKilkanaMediaModel $m) => $this->log('media', 'uploaded', $m->title ?: ($m->original_name ?: 'a file'), '/media'));
        SnkKilkanaMediaModel::deleted(function (SnkKilkanaMediaModel $m) {
            $this->log('delete', $m->isForceDeleting() ? 'deleted for good' : 'deleted', $m->title ?: ($m->original_name ?: 'a file'), $m->isForceDeleting() ? '/media' : '/trash');
        });
        SnkKilkanaMediaModel::restored(fn (SnkKilkanaMediaModel $m) => $this->log('restore', 'restored', $m->title ?: ($m->original_name ?: 'a file'), '/media'));

        // `layout` es un enum: su valor, no el objeto, que no sabe volverse cadena.
        SnkKilkanaLayoutModel::saved(fn (SnkKilkanaLayoutModel $l) => $this->log('front', 'published the front page', ucfirst($l->layout?->value ?? 'front'), '/front'));

        SnkKilkanaSettingModel::updated(function (SnkKilkanaSettingModel $s) {
            if ($s->wasChanged('theme_id')) {
                $nombres = SnkKilkanaThemeModel::query()->whereKey([$s->getOriginal('theme_id'), $s->theme_id])->pluck('name', 'id');
                $antes = $nombres->get($s->getOriginal('theme_id'));
                $despues = $nombres->get($s->theme_id, 'a theme');
                $this->log('theme', 'applied the theme', $despues, '/themes', ['before' => $antes, 'after' => $despues]);

                return;
            }
            if ($s->wasChanged('theme_overrides')) {
                $this->log('theme', 'adjusted the theme of', $s->tenant_name ?: 'the site', '/themes');

                return;
            }
            $this->log('settings', 'changed the settings of', $s->tenant_name ?: 'the site', '/settings');
        });
    }

    /** Una pieza cuenta su estado: es lo único que un registro necesita de ella. */
    private function post(SnkKilkanaPostModel $post, bool $nueva): void
    {
        if ($nueva) {
            $this->log($post->status === SnkKilkanaPostStatusEnum::Review ? 'review' : 'edit', $post->status === SnkKilkanaPostStatusEnum::Review ? 'sent to review' : 'created a draft', $post->title, '/posts');

            return;
        }
        if (! $post->wasChanged('status')) {
            if ($post->wasChanged(['title', 'body_blocks', 'excerpt'])) {
                $this->log('edit', 'edited', $post->title, '/posts');
            }

            return;
        }

        [$kind, $verb] = match ($post->status) {
            SnkKilkanaPostStatusEnum::Published => ['publish', 'published'],
            SnkKilkanaPostStatusEnum::Scheduled => ['schedule', 'scheduled'],
            SnkKilkanaPostStatusEnum::Review => ['review', 'sent to review'],
            SnkKilkanaPostStatusEnum::Draft => $post->getOriginal('status') === SnkKilkanaPostStatusEnum::Published
                ? ['publish', 'unpublished']
                : ['edit', 'returned to draft'],
        };
        $this->log($kind, $verb, $post->title, '/posts');
    }
}
