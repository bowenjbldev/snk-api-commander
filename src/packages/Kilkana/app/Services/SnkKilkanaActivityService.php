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
    private bool $muted = false;

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
     * pagaría en cada carga para no pintarlas. `$source` es lo ya filtrado cuando quien llama
     * tiene pestaña o persona; sin él, todo lo visible.
     *
     * @return array{data: Collection<int, array<string, mixed>>, seen_at: ?string}
     */
    public function bell(int $count, ?Builder $source = null): array
    {
        // Del array crudo y no por su nombre: una cuenta recién creada no lleva sus columnas
        // anulables, y `actingAs()` conserva ese modelo en memoria en vez de releerlo.
        $seenAt = auth()->user()?->getAttributes()['activity_seen_at'] ?? null;

        return [
            'data' => ($source ?? $this->visible())->limit(min($count, self::COUNT_MAX))->get()->pipe(SnkKilkanaActivityResource::collection(...)),
            // Hasta cuándo miró esta cuenta: lo posterior es lo que la campana marca.
            'seen_at' => $seenAt ? Carbon::parse($seenAt)->toIso8601String() : null,
        ];
    }

    /**
     * Corre algo sin que los modelos lo cuenten. Para lo que ya se apunta con su propio verbo
     * —fundir dos etiquetas borra una, restaurar una versión guarda la pieza— y para sembrar.
     */
    public function quiet(callable $work): mixed
    {
        $wasMuted = $this->muted;
        $this->muted = true;
        try {
            return $work();
        } finally {
            $this->muted = $wasMuted;
        }
    }

    /**
     * Apunta un movimiento.
     *
     * `$verb` es una clave y no una frase: la frase la pone el panel en el idioma de quien lee.
     * Las cifras y los nombres que esa frase necesita van en `params`, y `$what` es solo el
     * nombre de lo tocado —vacío si no lo hay—, nunca un relleno escrito aquí.
     *
     * @param  array{before?: ?string, after?: ?string, params?: array<string, int|string>, user?: ?CoreUserModel, force?: bool}  $extra
     */
    public function log(string $kind, string $verb, string $what, string $href, array $extra = []): void
    {
        if ($this->muted && ! ($extra['force'] ?? false)) {
            return;
        }
        try {
            $actor = array_key_exists('user', $extra) ? $extra['user'] : auth()->user();
            // Del array crudo: quien actúa puede llegar como un modelo que no trajo todas sus
            // columnas —una sesión de prueba conserva el que se creó, y una consulta recortada
            // no selecciona lo que no pinta—. Pedirlas por su nombre lanzaba, el `catch` de
            // abajo se lo tragaba con un `report()`, y la línea del registro **desaparecía sin
            // que nada fallara**: justo lo que la auditoría no puede hacer.
            $actorAttributes = $actor?->getAttributes() ?? [];

            SnkKilkanaActivityModel::create([
                'user_id' => $actor?->getKey(),
                'actor_name' => $actor === null
                    ? SnkKilkanaActivityModel::SYSTEM
                    : (trim(($actorAttributes['name'] ?? '').' '.($actorAttributes['lastname'] ?? '')) ?: SnkKilkanaActivityModel::SYSTEM),
                'actor_role' => $actor?->roles->first()?->name,
                'kind' => $kind,
                'verb' => $verb,
                'what' => mb_substr($what, 0, 500),
                'params' => $extra['params'] ?? null,
                'before' => isset($extra['before']) ? mb_substr($extra['before'], 0, 255) : null,
                'after' => isset($extra['after']) ? mb_substr($extra['after'], 0, 255) : null,
                'href' => $href,
                'created_at' => now(),
            ]);
        } catch (\Throwable $error) {
            report($error);
        }
    }

    /**
     * Las frases que el registro guardaba antes de guardar claves, con la clave que les toca.
     *
     * Solo sirven para reescribir las filas viejas —la migración `store_activity_verbs_as_keys`—
     * y para probar esa reescritura: nada nuevo se escribe ya así. Mientras quede una base sin
     * migrar, esta tabla no se borra.
     */
    public const LEGACY = [
        'created a draft' => 'draft_created',
        'sent to review' => 'sent_to_review',
        'edited' => 'edited',
        'published' => 'published',
        'scheduled' => 'scheduled',
        'unpublished' => 'unpublished',
        'returned to draft' => 'returned_to_draft',
        'deleted' => 'deleted',
        'deleted for good' => 'deleted_for_good',
        'restored' => 'restored',
        'restored from the trash' => 'restored_from_trash',
        'restored an earlier version of' => 'version_restored',
        'created the category' => 'category_created',
        'renamed the category' => 'category_renamed',
        'deleted the category' => 'category_deleted',
        'created the tag' => 'tag_created',
        'deleted the tag' => 'tag_deleted',
        'merged the tags' => 'tags_merged',
        'uploaded' => 'uploaded',
        'published the front page' => 'front_published',
        'changed the front page of' => 'front_changed',
        'applied the theme' => 'theme_applied',
        'adjusted the theme of' => 'theme_adjusted',
        'changed the settings of' => 'settings_changed',
        'blocked the reader' => 'reader_blocked',
        'unblocked the reader' => 'reader_unblocked',
        'removed the reader account' => 'reader_removed',
        'sent the newsletter issue' => 'issue_sent',
        'imported subscribers' => 'subscribers_imported',
        'invited' => 'seat_invited',
        'suspended' => 'seat_suspended',
        'restored the seat of' => 'seat_restored',
        'blocked the deploy:' => 'deploy_blocked',
        'passed the output gate on' => 'gate_passed',
    ];

    /** Los lotes de moderación llevaban la cifra dentro de la frase: «approved 3 comments on». */
    private const LEGACY_BULK = [
        'approved' => 'comments_approved',
        'rejected' => 'comments_rejected',
        'marked as spam' => 'comments_spammed',
        'reopened' => 'comments_reopened',
        'deleted' => 'comments_deleted',
    ];

    /**
     * Una fila escrita con frases, pasada a claves: el verbo, lo que queda de `what` y los
     * `params` que la frase llevaba dentro. Nulo si el verbo no es una frase de antes, que es lo
     * que hace idempotente la migración.
     *
     * @return array{verb: string, what: string, params: ?array<string, int|string>}|null
     */
    public static function fromLegacy(string $verb, string $what, ?string $after = null): ?array
    {
        $params = null;
        if (preg_match('/^(approved|rejected|marked as spam|reopened|deleted) (\d+) comments? on$/', $verb, $batch)) {
            $verb = self::LEGACY_BULK[$batch[1]];
            $params = ['count' => (int) $batch[2]];
            if (preg_match('/^(\d+) posts$/', $what, $matches)) {
                $params['posts'] = (int) $matches[1];
                $what = '';
            }
        } elseif (isset(self::LEGACY[$verb])) {
            $verb = self::LEGACY[$verb];
        } else {
            return null;
        }
        // Los rellenos que el registro escribía cuando no había nombre.
        if (in_array($what, ['a file', 'a reader', 'a theme', 'the site'], true)) {
            $what = '';
        }

        $g = [];
        if (in_array($verb, ['front_published', 'front_changed'], true)) {
            [$what, $params] = ['', ['layout' => strtolower($what) === 'section' ? 'section' : 'front']];
        } elseif ($verb === 'deploy_blocked' && preg_match('/^(\d+) of (\d+) routes fail$/', $what, $g)) {
            [$what, $params] = ['', ['failing' => (int) $g[1], 'total' => (int) $g[2]]];
        } elseif ($verb === 'gate_passed' && preg_match('/^(\d+) routes$/', $what, $g)) {
            [$what, $params] = ['', ['total' => (int) $g[1]]];
        } elseif ($verb === 'subscribers_imported' && preg_match('/^(\d+) addresses$/', $what, $g)) {
            [$what, $params] = ['', ['count' => (int) $g[1]]];
        } elseif ($verb === 'tags_merged' && preg_match('/ into (.+)$/', $what, $g)) {
            $what = $after ?? $g[1];
        } elseif ($verb === 'seat_invited' && preg_match('/^(.+) as (.+)$/', $what, $g)) {
            [$what, $params] = [$g[1], ['role' => strtolower($g[2])]];
        }

        return ['verb' => $verb, 'what' => $what, 'params' => $params];
    }

    /** Engancha los modelos que se cuentan solos. Se llama una vez, desde el proveedor. */
    public function observe(): void
    {
        SnkKilkanaPostModel::created(fn (SnkKilkanaPostModel $p) => $this->post($p, true));
        SnkKilkanaPostModel::updated(fn (SnkKilkanaPostModel $p) => $this->post($p, false));
        SnkKilkanaPostModel::deleted(fn (SnkKilkanaPostModel $p) => $this->log('delete', $p->isForceDeleting() ? 'deleted_for_good' : 'deleted', $p->title, $p->isForceDeleting() ? '/posts' : '/trash'));
        SnkKilkanaPostModel::restored(fn (SnkKilkanaPostModel $p) => $this->log('restore', 'restored', $p->title, '/posts'));

        SnkKilkanaCategoryModel::created(fn (SnkKilkanaCategoryModel $c) => $this->log('category', 'category_created', $c->name, '/categories'));
        SnkKilkanaCategoryModel::updated(function (SnkKilkanaCategoryModel $c) {
            if ($c->wasChanged('name')) {
                $this->log('category', 'category_renamed', "{$c->getOriginal('name')} → {$c->name}", '/categories', ['before' => $c->getOriginal('name'), 'after' => $c->name]);
            }
        });
        SnkKilkanaCategoryModel::deleted(fn (SnkKilkanaCategoryModel $c) => $this->log('category', 'category_deleted', $c->name, '/categories'));

        SnkKilkanaTagModel::created(fn (SnkKilkanaTagModel $t) => $this->log('tag', 'tag_created', $t->name, '/tags'));
        SnkKilkanaTagModel::deleted(fn (SnkKilkanaTagModel $t) => $this->log('tag', 'tag_deleted', $t->name, '/tags'));

        SnkKilkanaMediaModel::created(fn (SnkKilkanaMediaModel $m) => $this->log('media', 'uploaded', $m->title ?: ($m->original_name ?: ''), '/media'));
        SnkKilkanaMediaModel::deleted(function (SnkKilkanaMediaModel $m) {
            $this->log('delete', $m->isForceDeleting() ? 'deleted_for_good' : 'deleted', $m->title ?: ($m->original_name ?: ''), $m->isForceDeleting() ? '/media' : '/trash');
        });
        SnkKilkanaMediaModel::restored(fn (SnkKilkanaMediaModel $m) => $this->log('restore', 'restored', $m->title ?: ($m->original_name ?: ''), '/media'));

        // `layout` es un enum: su valor, no el objeto, que no sabe volverse cadena.
        SnkKilkanaLayoutModel::saved(fn (SnkKilkanaLayoutModel $l) => $this->log('front', 'front_published', '', '/front', ['params' => ['layout' => $l->layout?->value ?? 'front']]));

        SnkKilkanaSettingModel::updated(function (SnkKilkanaSettingModel $s) {
            if ($s->wasChanged('theme_id')) {
                $names = SnkKilkanaThemeModel::query()->whereKey([$s->getOriginal('theme_id'), $s->theme_id])->pluck('name', 'id');
                $previousName = $names->get($s->getOriginal('theme_id'));
                $currentName = (string) $names->get($s->theme_id, '');
                $this->log('theme', 'theme_applied', $currentName, '/themes', ['before' => $previousName, 'after' => $currentName]);

                return;
            }
            if ($s->wasChanged('theme_overrides')) {
                $this->log('theme', 'theme_adjusted', (string) $s->tenant_name, '/themes');

                return;
            }
            $this->log('settings', 'settings_changed', (string) $s->tenant_name, '/settings');
        });
    }

    /** Una pieza cuenta su estado: es lo único que un registro necesita de ella. */
    private function post(SnkKilkanaPostModel $post, bool $isNew): void
    {
        if ($isNew) {
            $this->log($post->status === SnkKilkanaPostStatusEnum::Review ? 'review' : 'edit', $post->status === SnkKilkanaPostStatusEnum::Review ? 'sent_to_review' : 'draft_created', $post->title, '/posts');

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
            SnkKilkanaPostStatusEnum::Review => ['review', 'sent_to_review'],
            SnkKilkanaPostStatusEnum::Draft => $post->getOriginal('status') === SnkKilkanaPostStatusEnum::Published
                ? ['publish', 'unpublished']
                : ['edit', 'returned_to_draft'],
        };
        $this->log($kind, $verb, $post->title, '/posts');
    }
}
