<?php

namespace Packages\Kilkana\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Packages\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostRevisionModel;
use Packages\Kilkana\Models\SnkKilkanaPostSlugModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Support\SnkKilkanaRichText;

class SnkKilkanaPostService
{
    /**
     * El listado editorial, con la audiencia que el panel enseña.
     *
     * **Una suma y no tres.** Aqui hubo tambien `views_day` y `views_week`, y no las pintaba
     * nadie: eran dos subconsultas correlacionadas por fila, calculadas y tiradas en cada
     * peticion del listado. Medido en 522 piezas y 12 212 filas de audiencia, ordenar por
     * lecturas pasaba de 5,9 a 18,6 ms de SQL solo por ellas — porque ordenar por el alias de
     * una correlacionada obliga a calcularla para TODAS las filas, no para las veinticinco que
     * se devuelven. Las ventanas de dia y semana siguen existiendo donde se usan, en
     * `SnkKilkanaPublicService`, que tiene las suyas.
     *
     * `engaged`, no `opens`: la API de audiencia cuenta lecturas reales.
     */
    public function query(int $userId, bool $canEditOthers, ?int $dias = null): Builder
    {
        // Con ventana, la suma cuenta solo esos dias; sin ella, el archivo entero. Es UNA suma
        // y no dos, porque el panel enseña una cifra: mandar las dos y elegir en el cliente
        // seria pagar la subconsulta que no se pinta, que es lo que ya costo antes.
        $audiencia = $dias === null
            ? fn (Builder $q) => $q
            : fn (Builder $q) => $q->where('date', '>=', now()->subDays($dias - 1)->toDateString());

        return $this->scopeToOwner(
            SnkKilkanaPostModel::query()->withSum(['views as views_total' => $audiencia], 'engaged'),
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

    /** Por qué columna ordena cada clave de la vista. `reads` es el alias del `withSum`. */
    private const SORTS = [
        'date' => 'publish_at',
        'title' => 'title',
        'reads' => 'views_total',
    ];

    /**
     * El filtro del listado editorial: estado, sección, búsqueda y orden.
     *
     * Los valores llegan ya saneados por el controlador, que es quien conoce la URL.
     * Aquí solo se traducen a cláusulas.
     */
    public function filter(Builder $query, array $filters): Builder
    {
        if (($status = $filters['status'] ?? null) !== null) {
            $query->where('status', $status);
        }

        if (($category = $filters['category'] ?? null) !== null) {
            $query->whereHas('category', fn (Builder $seccion) => $seccion->where('name', $category));
        }

        if (($term = $filters['q'] ?? null) !== null) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn (Builder $todo) => $todo
                ->where('title', 'like', $like)
                ->orWhereHas('author', fn (Builder $firma) => $this->matchesByline($firma, $like)));
        }

        $column = self::SORTS[$filters['sort'] ?? 'date'] ?? 'publish_at';
        $direction = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // El desempate por `id` no es adorno: sin él, dos piezas con la misma fecha pueden
        // salir en distinto orden en dos consultas, y entonces paginar repite una fila en la
        // página 2 y se salta otra. Un orden estable es requisito de la paginación.
        return $query->orderBy($column, $direction)->orderBy('id', 'desc');
    }

    /**
     * Buscar por firma es buscar por el nombre **publicado**, que es derivado.
     *
     * Es el alias si lo hay y el de la cuenta si no. Mirar los dos a secas encontraría
     * las piezas firmadas «Redacción» al teclear el nombre de quien las escribió, que es
     * justo lo que la firma colectiva existe para no decir.
     */
    private function matchesByline(Builder $firma, string $like): Builder
    {
        return $firma
            ->where(fn (Builder $conAlias) => $conAlias
                ->whereNotNull('alias')
                ->where('alias', '<>', '')
                ->where('alias', 'like', $like))
            ->orWhere(fn (Builder $sinAlias) => $sinAlias
                ->where(fn (Builder $vacio) => $vacio->whereNull('alias')->orWhere('alias', ''))
                ->whereHas('user', fn ($cuenta) => $cuenta->where('name', 'like', $like)));
    }

    /**
     * Cuántas piezas hay de cada estado, **sin ningún filtro puesto**.
     *
     * Es lo que sostiene que «Draft 35» siga diciendo 35 mientras se miran los publicados:
     * la pestaña cuenta el archivo, no la página. Solo respeta el acotado por propietario,
     * porque quien no puede editar lo de otros tampoco debe contarlo.
     *
     * Las claves salen del enum, así que un estado nuevo aparece aquí sin tocar nada.
     */
    public function countsByStatus(int $userId, bool $canEditOthers): array
    {
        $rows = $this->scopeToOwner(SnkKilkanaPostModel::query(), $userId, $canEditOthers)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];
        foreach (SnkKilkanaPostStatusEnum::cases() as $estado) {
            $counts[$estado->value] = (int) ($rows[$estado->value] ?? 0);
        }

        return $counts;
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
    public function bulk(array $ids, string $action, int $userId, bool $canEditOthers, array $tags = []): int
    {
        return DB::transaction(function () use ($ids, $action, $userId, $canEditOthers, $tags) {
            $query = $this->scopeToOwner(SnkKilkanaPostModel::query(), $userId, $canEditOthers)
                ->whereIn('id', $ids);

            if ($action === 'delete') {
                $count = $query->count();
                $query->delete();

                return $count;
            }

            if ($action === 'tag' || $action === 'untag') {
                return $this->retag($query->get(), $action === 'tag', $tags, $userId);
            }

            // El mapa vive en el enum: el controlador lo usa para decidir el permiso y aqui se
            // usa para escribir, y dos copias es como una acepta lo que la otra no sabe mover.
            $destino = SnkKilkanaPostStatusEnum::fromBulkAction($action);
            if ($destino === null) {
                return 0;
            }

            // Sacar a la calle exige lo mismo por el lote que por el editor. `required_if`
            // solo mira la peticion, y una peticion de lote son ids: marcar veinte borradores
            // y pulsar «Publicar» publicaba veinte piezas sin entradilla. Las que no estan
            // listas se quedan como estan y el recuento lo dice, en vez de fallar el lote
            // entero por una — el resto SI estaban listas.
            $exigeEntradilla = in_array($destino->value, SnkKilkanaPostStatusEnum::publishing(), true);

            $count = 0;
            foreach ($query->get() as $post) {
                if ($exigeEntradilla && ! $post->hasExcerpt()) {
                    continue;
                }

                $post->status = $destino;
                // Publicar a mano una pieza programada la saca AHORA: dejarle la fecha prevista
                // la ordenaria manana en la portada, el sitemap y el feed.
                if ($destino === SnkKilkanaPostStatusEnum::Published
                    && ($post->publish_at === null || $post->publish_at->isFuture())) {
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
     * Que fecha lleva una pieza que sale a la calle, dado lo que ya tiene y lo que se pide.
     *
     * **Publicar es AHORA**, y por eso la fecha no se le pide a nadie: un borrador no tiene
     * `publish_at` —se sella al salir— asi que exigirsela al panel es exigirle un dato que no
     * tiene donde pedir, y era por lo que desde el editor **no se podia publicar una pieza**.
     *
     * Y pisa una fecha futura: publicar a mano una programada la saca ahora, y dejarle la
     * prevista la ordenaria manana en la portada, el sitemap y el feed.
     *
     * `scheduled` no entra: una programada sin fecha es un error de verdad, no una que haya
     * que inventar. Devuelve `null` cuando no hay nada que sellar, y quien llama deja lo que
     * hubiera.
     */
    public static function stampFor(string $status, ?string $wanted): ?string
    {
        if ($status !== SnkKilkanaPostStatusEnum::Published->value) {
            return null;
        }

        $vacia = $wanted === null || trim($wanted) === '';

        return $vacia || Carbon::parse($wanted)->isFuture() ? now()->toDateTimeString() : null;
    }

    /**
     * Lo que devuelve una fila del listado, y **nada mas**.
     *
     * Es una lista blanca y no una de ocultos a proposito: con una lista de ocultos, cada
     * columna nueva de la tabla sale al JSON el dia que se anade y nadie lo decide. Aqui lo
     * que sale esta escrito, y lo que se anada tiene que anadirse tambien aqui.
     *
     * **Y no es aseo, es tiempo.** Medido con 522 piezas: serializar veinticinco filas pasa de
     * 28,0 a 14,5 ms y de 59,7 a 14,2 kB. El 64% del coste por fila eran las relaciones, y la
     * fila mandaba `author` y `category` ENTEROS —catorce columnas cada uno, mas la madre de la
     * seccion y su cuenta de usuario— para pintar dos nombres.
     *
     * El editor NO pasa por aqui: `show` va por `findModel`, que devuelve la pieza entera. Son
     * dos preguntas distintas —«que hay en el archivo» y «que es esta pieza»— y responderlas
     * con la misma forma es lo que hacia que la tabla pagara el peso del editor.
     *
     * Vive aqui y no en el controlador porque es parte de lo que ES el listado, y porque asi
     * hay donde comprobarla: la gemela de esta lista es `postListFromApi` en el frontend, y si
     * una campo se cae de aqui, alli deja de llegar sin que nada falle en este lado.
     *
     * Lo que sigue calculandose aunque no salga: `comments_count`, que es una subconsulta del
     * `$withCount` del modelo. Son 0,1 ms y quitarlo obliga a rehacer el `$withCount` global.
     */
    public const FILA_DEL_LISTADO = [
        'id', 'title', 'excerpt', 'slug', 'status', 'content_tier',
        'publish_at', 'views_total', 'cover', 'category', 'author', 'tags',
    ];

    public function asListRow(SnkKilkanaPostModel $post): void
    {
        $post->setVisible(self::FILA_DEL_LISTADO);
        // La seccion y la firma, por su nombre publicado; la portada, solo su id —la mediateca
        // la resuelve el panel—; y las etiquetas, por nombre, que es como viajan al escribir.
        $post->category?->setVisible(['id', 'name']);
        $post->author?->setVisible(['id', 'name']);
        $post->coverMedia?->setVisible(['id']);
        $post->tags->each->setVisible(['id', 'name']);
    }

    /**
     * Anade o quita etiquetas en tanda.
     *
     * Por NOMBRE y no por id: es lo que escribe el resto del API (`firstOrCreateByName`) y lo
     * unico que significa algo fuera de esta base. Anadir CREA la etiqueta que no exista, que
     * es como se etiqueta de verdad — la cobertura empieza antes de que nadie abra el
     * vocabulario a darla de alta.
     *
     * `syncWithoutDetaching` y no `sync`: anadir «sequia» a veinte piezas no puede quitarles
     * las que ya tenian. Quitar solo desengancha, nunca borra la fila del vocabulario: una
     * etiqueta sin usos sigue existiendo, y es la pantalla de Etiquetas la que decide si se va.
     *
     * `updated_by` se sella igual que en un cambio de estado: etiquetar es editar.
     *
     * @param  Collection<int, SnkKilkanaPostModel>  $posts
     * @param  string[]  $nombres
     */
    private function retag($posts, bool $anadir, array $nombres, int $userId): int
    {
        $ids = collect($nombres)
            ->map(fn (string $nombre) => trim($nombre))
            ->filter()
            ->unique()
            ->map(fn (string $nombre) => SnkKilkanaTagModel::firstOrCreateByName($nombre)->id)
            ->all();

        if ($ids === []) {
            return 0;
        }

        foreach ($posts as $post) {
            $anadir ? $post->tags()->syncWithoutDetaching($ids) : $post->tags()->detach($ids);
            $post->forceFill(['updated_by' => $userId])->save();
        }

        return $posts->count();
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

        // Y con entradilla. `required_if` vive en el controlador, asi que solo mira la
        // peticion: a `scheduled` se puede llegar por el lote, que no valida contenido, y
        // entonces este cron sacaria a la calle una pieza sin entradilla a las seis de la
        // manana sin que nadie lo viera. Se queda programada, que es un estado del que se
        // puede salir escribiendola.
        [$listas, $aparcadas] = $due->partition->hasExcerpt();

        foreach ($listas as $post) {
            $post->update(['status' => SnkKilkanaPostStatusEnum::Published]);
        }

        // Y se dice. Una pieza que no sale a su hora no rompe nada: simplemente no ocurre, y
        // quien la programo la ve «Programada» a mediodia sin entender por que. Esta linea es
        // la unica pista que va a existir de por que.
        if ($aparcadas->isNotEmpty()) {
            Log::warning('kilkana: piezas programadas sin entradilla, no publicadas', [
                'count' => $aparcadas->count(),
                'ids' => $aparcadas->pluck('id')->all(),
            ]);
        }

        return $listas->count();
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
