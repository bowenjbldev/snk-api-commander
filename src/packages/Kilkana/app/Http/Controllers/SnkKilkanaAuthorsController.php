<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Enums\SnkKilkanaAuthorKindEnum;
use Packages\Kilkana\Http\Resources\SnkKilkanaAuthorResource;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Spatie\QueryBuilder\QueryBuilder;

#[Group('Authors')]
class SnkKilkanaAuthorsController extends CoreBaseApiController
{
    /** `rule()` es una llamada, y una propiedad no se puede inicializar con eso: va aqui. */
    public function __construct()
    {
        foreach (['validationRulesStore', 'validationRulesUpdate'] as $property) {
            // Que clase de firma es. Nace `contributor`, que es el alta mas comun.
            $this->{$property}['kind'] = 'sometimes|'.SnkKilkanaAuthorKindEnum::rule();
        }
    }

    protected string $modelClass = SnkKilkanaAuthorModel::class;

    protected string $indexPermission = 'index authors';

    protected string $showPermission = 'show authors';

    protected string $storePermission = 'create authors';

    protected string $updatePermission = 'update authors';

    protected string $destroyPermission = 'delete authors';

    /** La foto de la firma vive en la biblioteca: `?with=avatarMedia` la resuelve a URL para que
     *  el consumidor no reciba solo un id opaco. */
    protected array $allowedIncludes = ['avatarMedia'];

    /**
     * Cada firma sale con cuanto ha publicado, cuando fue la ultima y con que asiento cruza.
     *
     * Tres subconsultas por fila y once filas, no un recuento por peticion: el panel enseña
     * «Marta Beneyto · 34 · 26 Aug» y sin esto la unica forma de saberlo era cruzar el archivo
     * entero en el cliente. `last_published_at` mira solo lo publicado —un borrador de hoy no
     * es actividad de la firma— y las URLs retiradas viajan porque la ficha enseña su 301.
     *
     * **`seat` es una subconsulta y no un accesor del modelo, y esa diferencia es lo que lo
     * mantiene dentro del panel.** Es el correo con el que se ENTRA, asi que anexado al modelo
     * viajaba en cada firma que serializa cualquiera —y la portada publica y la pagina de cada
     * articulo anidan la firma entera—: el correo de acceso de la redaccion salia a la calle
     * sin sesion. Aqui solo existe para quien lista firmas en el panel, y ningun serializador
     * futuro tiene que acordarse de esconderlo.
     *
     * Va en `decorate()` y no en el modelo porque **solo lo quiere quien lista**: pedir una
     * firma para editarla no necesita contar sus piezas. Y en `decorate()` y no en `newQuery()`
     * porque `?fields=` hace `select()`: puesto antes, el asiento y los recuentos se perderían.
     */
    protected ?string $resourceClass = SnkKilkanaAuthorResource::class;

    protected array $allowedFields = [
        'id', 'slug', 'alias', 'kind', 'job_title', 'bio', 'website', 'public_email', 'socials',
        'noindex', 'redirect_to', 'user_id',
        // La foránea del retrato: sin ella `?include=avatarMedia` llega nulo. Y de la foto, el
        // prefijo es la relación en plural snake, no la tabla.
        'avatar_media_id', 'avatar_medias.id', 'avatar_medias.url',
    ];

    protected function decorate(QueryBuilder $query): QueryBuilder
    {
        // La cuenta va siempre cargada —`$with` del modelo, porque el nombre publicado sale de
        // ella— y sin su llave no se carga: con el modo estricto revienta. Quien recorta no
        // tiene por qué saberlo, y la clave no sale: `$hidden` la tapa igual que siempre.
        if ($query->getEloquentBuilder()->getQuery()->columns !== null) {
            $query->addSelect('kilkana_authors.user_id');
        }

        return $query->asCatalogue();
    }

    /**
     * Borrar una firma dice QUÉ lo impide, en vez de reventar con el SQL a la vista.
     *
     * `kilkana_posts.author_id` es RESTRICT —la hemeroteca no puede quedarse sin autor— y al
     * saltar devolvía un 500 con el nombre de la tabla y de la restricción dentro. Quien lo
     * recibía no distinguía «esta firma tiene artículos» de «el servidor se ha roto», y de
     * paso se publicaba el esquema.
     *
     * Se cuenta antes para poder decir cuántos son: retirar una firma es una decisión
     * editorial, y quien la toma necesita saber el tamaño de lo que va a mover.
     */
    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $posts = SnkKilkanaPostModel::query()->where('author_id', $id)->count();
        if ($posts > 0) {
            return response()->json(['error' => 'Byline in use', 'posts' => $posts], 409);
        }

        try {
            return parent::destroy($id);
        } catch (QueryException) {
            // Entre el recuento y el borrado cabe una escritura. Nunca un 500 con SQL dentro.
            return response()->json(['error' => 'Byline in use'], 409);
        }
    }

    protected array $validationRulesStore = [
        // El contacto de la pagina de autor, que **no es el correo de la cuenta**: aquel es
        // con el que se entra al panel y este es con el que se escribe a quien firma.
        'public_email' => 'nullable|email|max:255',
        // El nombre NO se manda: se publica el de la cuenta. `alias` solo cuando firma con
        // seudónimo, y entonces es lo que se ve — y **es obligatorio si no hay cuenta**, que
        // es lo que impone el modelo: sin ninguno de los dos no hay nombre que publicar.
        'alias' => 'nullable|string|max:255',
        // Cargo publicable. No es `users.company`: aquella es la organización del CRM y no
        // sale al público; esta es la que firma bajo el nombre.
        'job_title' => 'nullable|string|max:255',
        // Nulo aquí sí: es la señal de «derívalo tú», y lo hace el modelo al crear.
        'slug' => 'nullable|string|max:255|unique:kilkana_authors,slug',
        'bio' => 'nullable|string',
        'seo_title' => 'nullable|string|max:500',
        'seo_description' => 'nullable|string',
        'noindex' => 'sometimes|boolean',
        'avatar_media_id' => 'nullable|integer|exists:kilkana_media,id',
        // Las dos acaban en un `href` de la ficha pública, así que el esquema se acota aquí:
        // sin esto pasaba `javascript:` y `data:text/html,<script>…`, y el endpoint público
        // los servía tal cual.
        'website' => 'nullable|url:http,https|max:2048',
        'socials' => 'nullable|array',
        'socials.*' => 'url:http,https|max:2048',
        // La cuenta que firma, **cuando la hay**: una agencia publica sin entrar al panel, y
        // exigirla obligaba a inventar una cuenta de servicio por agencia solo para satisfacer
        // la clave foránea. Sigue siendo 1:1 con el resto de firmas cuando se manda.
        'user_id' => 'nullable|integer|exists:users,id|unique:kilkana_authors,user_id',
    ];

    protected array $validationRulesUpdate = [
        'public_email' => 'nullable|email|max:255',
        'alias' => 'nullable|string|max:255',
        'job_title' => 'nullable|string|max:255',
        // Aquí NO puede ser nulo: la columna es NOT NULL y el derivador solo corre al crear,
        // así que un `slug: null` en un update era un 500. Una firma publicada tiene URL
        // siempre; se puede cambiar, no vaciar.
        'slug' => 'sometimes|required|string|max:255|unique:kilkana_authors,slug,{id}',
        'bio' => 'nullable|string',
        'seo_title' => 'nullable|string|max:500',
        'seo_description' => 'nullable|string',
        'noindex' => 'sometimes|boolean',
        'avatar_media_id' => 'nullable|integer|exists:kilkana_media,id',
        'website' => 'nullable|url:http,https|max:2048',
        'socials' => 'nullable|array',
        'socials.*' => 'url:http,https|max:2048',
        // Cambiar de cuenta es cambiar de persona: se admite (una firma mal enlazada hay
        // que poder arreglarla), y desvincularla también —una firma puede dejar de tener
        // asiento sin dejar de publicar—. Sigue siendo 1:1 contra el resto de firmas.
        'user_id' => 'sometimes|nullable|integer|exists:users,id|unique:kilkana_authors,user_id,{id}',
    ];
}
