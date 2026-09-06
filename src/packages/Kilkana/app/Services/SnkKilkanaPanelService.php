<?php

namespace Packages\Kilkana\Services;

use Packages\Core\Support\CorePaginationSupport;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaGateResultModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;

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

    public function __construct(
        private readonly SnkKilkanaActivityService $registro,
        private readonly SnkKilkanaCommentService $comentarios,
        private readonly SnkKilkanaNewsletterService $boletin,
        private readonly SnkKilkanaPlanService $plan,
        private readonly SnkKilkanaPostService $piezas,
        private readonly SnkKilkanaReaderService $lectores,
        private readonly SnkKilkanaTrashService $papelera,
    ) {}

    /**
     * Lo que el armazón necesita para pintarse: los tres catálogos y las seis cifras del raíl.
     *
     * @return array<string, mixed>
     */
    public function bootstrap(): array
    {
        $clases = $this->papelera->kindsFor(auth()->user());

        return [
            // El mismo scope que sirve `GET categories`, `authors` y `tags`: la lista se arma
            // en el modelo justo para que las dos puertas no puedan enseñar columnas distintas.
            'categories' => $this->conPermiso('index categories', fn () => SnkKilkanaCategoryModel::query()->asCatalogue()->get()),
            'authors' => $this->conPermiso('index authors', fn () => SnkKilkanaAuthorModel::query()->asCatalogue()->get()),
            'tags' => $this->conPermiso('index tags', fn () => SnkKilkanaTagModel::query()->asCatalogue()->get()),
            'comments' => $this->cifra('index comments', fn () => [
                'counts' => $this->comentarios->countsByStatus(),
                'oldest_pending_at' => $this->comentarios->oldestPendingAt(),
            ]),
            'readers' => $this->cifra('index readers', fn () => ['accounts' => $this->lectores->accounts()->count()]),
            // La campana la guarda `can:view others posts` en su ruta, no un `indexPermission`.
            'activity' => $this->cifra('view others posts', fn () => $this->registro->bell(self::BELL)),
            // La papelera no tiene un permiso suyo: se ve si se puede borrar algo de lo que hay.
            'trash' => $clases === [] ? null : ['count' => $this->papelera->count($clases)],
            'gate' => $this->cifra('index posts', fn () => ['failing' => SnkKilkanaGateResultModel::failingCount()]),
            'newsletter' => $this->cifra('index subscribers', fn () => ['drafts' => $this->boletin->drafts()]),
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
        $puedeEditarAjenas = auth()->user()?->can('edit others posts') === true;
        $cifras = $this->piezas->countsByStatus($userId, $puedeEditarAjenas, auth()->user()?->can('view others posts') === true);

        // La misma página que sirve `GET posts`, y por la misma puerta: `KilkanaPanelCompositeTest`
        // compara sección a sección estas cuatro colas con las cuatro llamadas que sustituyen, y
        // lo que podía romper esa comparación era que aquí estuviera copiada.
        $cola = function (array $filtros, int $cuantas, ?int $dias) use ($userId, $puedeEditarAjenas, $cifras) {
            $pagina = $this->piezas->page(
                $this->piezas->query($userId, $puedeEditarAjenas, $dias),
                $filtros,
                $cuantas,
            );

            return [
                'data' => $pagina->items(),
                'meta' => CorePaginationSupport::meta($pagina),
                'counts' => $cifras['scoped'],
                'counts_all' => $cifras['all'],
            ];
        };

        $filtro = fn (?string $estado, string $orden, string $dir) => [
            'status' => $estado, 'category' => null, 'q' => null, 'sort' => $orden, 'dir' => $dir,
        ];

        return [
            // La cola de revisión va del más antiguo al más nuevo, al revés que el resto del
            // panel: lo que lleva más tiempo esperando es lo que primero bloquea a otro.
            'review' => $cola($filtro('review', 'date', 'asc'), self::QUEUE, null),
            'scheduled' => $cola($filtro('scheduled', 'date', 'asc'), self::QUEUE, null),
            'published' => $cola($filtro('published', 'date', 'desc'), self::QUEUE, null),
            'reads' => $cola($filtro(null, 'reads', 'desc'), self::MOST_READ, $reads),
            // El plan no tumba el tablero: es una tarjeta de facturación, y sin instancia
            // —o sin Commander delante— la pantalla se pinta igual sin ella.
            'plan' => $this->plan->payload(),
        ];
    }

    /** La sección que quien mira no puede ver llega nula, no cierra la pantalla. */
    private function conPermiso(string $permiso, callable $seccion): mixed
    {
        return auth()->user()?->can($permiso) === true ? $seccion() : null;
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
    private function cifra(string $permiso, callable $seccion): mixed
    {
        try {
            return $this->conPermiso($permiso, $seccion);
        } catch (\Throwable $fallo) {
            report($fallo);

            return null;
        }
    }
}
