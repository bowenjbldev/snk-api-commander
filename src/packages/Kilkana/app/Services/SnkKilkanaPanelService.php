<?php

namespace Packages\Kilkana\Services;

use Packages\Core\Support\CorePaginationSupport;
use Packages\Kilkana\Http\Resources\SnkKilkanaAuthorResource;
use Packages\Kilkana\Http\Resources\SnkKilkanaCategoryResource;
use Packages\Kilkana\Http\Resources\SnkKilkanaPostResource;
use Packages\Kilkana\Http\Resources\SnkKilkanaTagResource;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaGateResultModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Las dos pantallas que el panel pedía a trozos, cada una en una llamada.
 *
 * **No hay negocio nuevo aquí.** Cada sección la resuelve el servicio que ya la posee y esto
 * solo las compone: si una cifra cambia de definición, cambia en un sitio y las dos puertas
 * —el CRUD por recurso y esto— la cuentan igual.
 *
 * Lo que ahorra no es SQL, es peaje. Cada llamada autenticada paga entre 4,7 y 6,5 ms antes de
 * llegar a su lógica —resolver el inquilino, abrir su base, el freno, el kill-switch, Sanctum y
 * el permiso—, y el armazón lo pagaba **nueve veces en cada navegación**, con nueve conexiones
 * a la base abiertas y cerradas y nueve peticiones compitiendo por los mismos cuatro núcleos.
 *
 * Tres reglas, y las tres importan:
 *
 * - **Cada sección conserva su permiso.** La que quien mira no puede ver llega `null`, no
 *   tumba la pantalla con un 403: es lo que el armazón ya hacía con su `figure()`, y sin eso
 *   un moderador —que no tiene ninguno de los tres catálogos— se quedaría sin panel.
 * - **Cada sección viaja con la forma EXACTA del endpoint que sustituye.** El panel la lee con
 *   el mismo parseador que ya tenía; un contrato propio por pantalla sería una segunda forma
 *   que mantener sincronizada con la primera.
 * - **Solo lectura.** Escribir sigue yendo al CRUD por recurso, que es donde están la
 *   validación, la transacción y el registro de actividad.
 */
class SnkKilkanaPanelService
{
    /** Cuántos movimientos lleva la campana. Es lo que el armazón pedía con `activity?bell=6`. */
    public const BELL = 6;

    /** Cuántas piezas enseña cada cola del tablero, y cuántas «lo más leído». */
    public const QUEUE = 5;

    public const MOST_READ = 6;

    /**
     * Los idiomas en los que esta rotulado el panel.
     *
     * No es `SnkKilkanaSettingModel::LANGS`, y la diferencia importa: aquella es la lista de lo
     * que la calle sabe SERVIR —el idioma en el que se publica, con su `hreflang` y su formato de
     * fecha— y esta es la lista de lo que el panel esta TRADUCIDO. Un diario en gallego lo edita
     * hoy alguien con el panel en castellano o en ingles, y son dos hechos independientes.
     */
    public const PANEL_LOCALES = ['en', 'es'];

    public function __construct(
        private readonly SnkKilkanaActivityService $activityLog,
        private readonly SnkKilkanaBylineService $bylines,
        private readonly SnkKilkanaCommentService $comments,
        private readonly SnkKilkanaNewsletterService $newsletter,
        private readonly SnkKilkanaPlanService $plan,
        private readonly SnkKilkanaPostService $posts,
        private readonly SnkKilkanaReaderService $readers,
        private readonly SnkKilkanaTrashService $trash,
    ) {}

    /**
     * Lo que el armazón necesita para pintarse: los tres catálogos y las cifras del raíl.
     *
     * @return array<string, mixed>
     */
    public function bootstrap(): array
    {
        $kinds = $this->trash->kindsFor(auth()->user());

        // La única escritura de aquí, y es la misma regla que el alta de una cuenta: quien
        // puede escribir tiene firma. El administrador lo crea el provisioning, que no sabe de
        // periódicos, así que su firma nace la primera vez que abre el panel.
        $this->bylines->ensureForUser((int) auth()->id());

        return [
            // Lo que la puesta en marcha necesita para saber por dónde va, y lo que decide si el
            // panel manda a ella: la fecha de apertura, si hay tema puesto y cuántas piezas han
            // salido. Sin permiso propio porque no descubre nada que la calle no diga.
            'setup' => $this->setup(),
            // Quien mira: por ahora solo en que idioma quiere el panel. Nulo es «lo que diga el
            // navegador», que es con lo que nace toda cuenta. Se lee de la fila y no del modelo
            // autenticado: ese modelo puede no traer la columna —el de `actingAs` en las pruebas
            // no la trae, y el modo estricto lo convierte en excepcion—, y de la fila sale siempre
            // lo que hay guardado.
            'account' => ['locale' => auth()->user()?->newQuery()->whereKey(auth()->id())->value('panel_locale')],
            // El mismo scope y **el mismo Resource** que sirven `GET categories`, `authors` y
            // `tags`: el scope arma la consulta y el Resource la forma, justo para que las dos
            // puertas no puedan enseñar columnas distintas.
            'categories' => $this->withPermission('index categories', fn () => SnkKilkanaCategoryResource::collection(SnkKilkanaCategoryModel::query()->asCatalogue()->get())),
            'authors' => $this->withPermission('index authors', fn () => SnkKilkanaAuthorResource::collection(SnkKilkanaAuthorModel::query()->asCatalogue()->get())),
            'tags' => $this->withPermission('index tags', fn () => SnkKilkanaTagResource::collection(SnkKilkanaTagModel::query()->asCatalogue()->get())),
            // Las cifras de las pestañas de Piezas, con la forma que les da `GET posts?summary=counts`:
            // el raíl cuenta lo mismo que la pestaña «Todas», acotado a lo que ve quien mira.
            'posts' => $this->countOrNull('index posts', function () {
                $counts = $this->posts->countsByStatus(
                    (int) auth()->id(),
                    auth()->user()?->can('edit others posts') === true,
                    auth()->user()?->can('view others posts') === true,
                );

                return ['counts' => $counts['scoped'], 'counts_all' => $counts['all']];
            }),
            'comments' => $this->countOrNull('index comments', fn () => [
                'counts' => $this->comments->countsByStatus(),
                'oldest_pending_at' => $this->comments->oldestPendingAt(),
            ]),
            'readers' => $this->countOrNull('index readers', fn () => ['accounts' => $this->readers->accounts()->count()]),
            // La campana la guarda `can:view others posts` en su ruta, no un `indexPermission`.
            'activity' => $this->countOrNull('view others posts', fn () => $this->activityLog->bell(self::BELL)),
            // La papelera no tiene un permiso suyo: se ve si se puede borrar algo de lo que hay.
            'trash' => $kinds === [] ? null : ['count' => $this->trash->count($kinds)],
            'gate' => $this->countOrNull('index posts', fn () => ['failing' => SnkKilkanaGateResultModel::failingCount()]),
            'newsletter' => $this->countOrNull('index subscribers', fn () => ['drafts' => $this->newsletter->drafts()]),
        ];
    }

    /** @return array{launched_at: ?string, theme: ?string, published: int} */
    private function setup(): array
    {
        $settings = SnkKilkanaSettingModel::query()
            ->with('theme:id,slug')
            ->find(1, ['launched_at', 'theme_id', 'theme_spec']);

        return [
            'launched_at' => $settings?->launched_at?->toIso8601String(),
            // El slug del tema puesto, y nulo mientras vaya desnudo: es lo que Themes marca
            // como «live» y lo que da por hecho el primer paso.
            'theme' => $settings?->theme_spec !== null ? $settings->theme?->slug : null,
            'published' => SnkKilkanaPostModel::query()->published()->count(),
        ];
    }

    /**
     * El tablero: el archivo desde cuatro ángulos, más el plan que paga el periódico.
     *
     * Las cifras de la barra de estados se calculan **una vez** y viajan con las cuatro colas,
     * porque son las mismas para todas y el panel las lee de la primera que le llega. Antes
     * eran cuatro llamadas y por tanto cuatro veces la misma consulta de recuentos.
     *
     * @param  ?int  $reads  la ventana de «lo más leído» en días, ya validada
     * @return array<string, mixed>
     */
    public function dashboard(?int $reads): array
    {
        $userId = (int) auth()->id();
        $canEditOthers = auth()->user()?->can('edit others posts') === true;
        $counts = $this->posts->countsByStatus($userId, $canEditOthers, auth()->user()?->can('view others posts') === true);

        // La misma página que sirve `GET posts`, y por la misma puerta: `KilkanaPanelCompositeTest`
        // compara sección a sección estas cuatro colas con las cuatro llamadas que sustituyen, y
        // lo que podía romper esa comparación era que aquí estuviera copiada.
        $queue = function (array $filters, int $count, ?int $days) use ($userId, $canEditOthers, $counts) {
            // Por el mismo builder que `GET posts`, y con el mismo techo: estas cuatro colas
            // **son** ese listado servido de otra manera, y `KilkanaPanelCompositeTest` compara
            // las dos respuestas con `assertSame`. Sin pasar por aquí, `?fields=` e `?include=`
            // funcionarían en una puerta y en la otra no.
            $query = QueryBuilder::for($this->posts->query($userId, $canEditOthers))
                ->allowedFields(...SnkKilkanaPostService::FIELDS)
                ->allowedIncludes(...SnkKilkanaPostService::INCLUDES);

            $page = $this->posts->page(
                $this->posts->withAudience($query, $days),
                $filters,
                $count,
            );

            return [
                'data' => SnkKilkanaPostResource::collection($page->getCollection()),
                'meta' => CorePaginationSupport::meta($page),
                'counts' => $counts['scoped'],
                'counts_all' => $counts['all'],
            ];
        };

        $filter = fn (?string $status, string $order, string $dir) => [
            'status' => $status, 'category' => null, 'q' => null, 'sort' => $order, 'dir' => $dir,
        ];

        return [
            // La cola de revisión va del más antiguo al más nuevo, al revés que el resto del
            // panel: lo que lleva más tiempo esperando es lo que primero bloquea a otro.
            'review' => $queue($filter('review', 'date', 'asc'), self::QUEUE, null),
            'scheduled' => $queue($filter('scheduled', 'date', 'asc'), self::QUEUE, null),
            'published' => $queue($filter('published', 'date', 'desc'), self::QUEUE, null),
            'reads' => $queue($filter(null, 'reads', 'desc'), self::MOST_READ, $reads),
            // El plan no tumba el tablero: es una tarjeta de facturación, y sin instancia
            // —o sin Commander delante— la pantalla se pinta igual sin ella.
            'plan' => $this->plan->payload(),
        ];
    }

    /** La sección que quien mira no puede ver llega nula, no cierra la pantalla. */
    private function withPermission(string $permission, callable $section): mixed
    {
        return auth()->user()?->can($permission) === true ? $section() : null;
    }

    /**
     * Una cifra del raíl que se rompe llega nula, igual que una sin permiso.
     *
     * Los tres catálogos NO se protegen así a propósito: sin ellos el armazón no sabe si hay
     * periódico y un 502 es la respuesta honesta. Una cifra es otra cosa —es un entero al lado
     * de un icono—, y ya costó una pantalla entera en «Something went wrong»: una columna que
     * faltaba en la consulta de la cola de moderación se llevó el panel por delante. Antes lo
     * paraba el armazón, que las pedía una a una; ahora vienen en la misma respuesta, así que
     * lo tiene que parar esto. Se reporta, no se traga.
     */
    private function countOrNull(string $permission, callable $section): mixed
    {
        try {
            return $this->withPermission($permission, $section);
        } catch (\Throwable $error) {
            report($error);

            return null;
        }
    }
}
