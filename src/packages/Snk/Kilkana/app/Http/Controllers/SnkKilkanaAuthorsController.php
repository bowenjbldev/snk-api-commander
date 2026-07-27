<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaAuthorModel;

#[Group('Authors')]
class SnkKilkanaAuthorsController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaAuthorModel::class;

    protected string $indexPermission = 'index authors';

    protected string $showPermission = 'show authors';

    protected string $storePermission = 'create authors';

    protected string $updatePermission = 'update authors';

    protected string $destroyPermission = 'delete authors';

    /** La foto de la firma vive en la biblioteca: `?with=avatarMedia` la trae resuelta a URL
     *  para el panel, que si no solo tendría un id que no puede pintar. */
    protected array $allowedWith = ['avatarMedia'];

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

        $articulos = SnkKilkanaPostModel::query()->where('author_id', $id)->count();
        if ($articulos > 0) {
            return response()->json(['error' => 'Byline in use', 'posts' => $articulos], 409);
        }

        try {
            return parent::destroy($id);
        } catch (QueryException) {
            // Entre el recuento y el borrado cabe una escritura. Nunca un 500 con SQL dentro.
            return response()->json(['error' => 'Byline in use'], 409);
        }
    }

    protected array $validationRulesStore = [
        // El nombre NO se manda: se publica el de la cuenta. `alias` solo cuando firma con
        // seudónimo, y entonces es lo que se ve.
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
        // IDENTIDAD de la firma: la cuenta. Obligatoria y 1:1 — el correo del periodista no
        // se duplica aquí, se lee de `users`. Antes esto era opcional y la identidad de
        // facto era el nombre, que `firstOrCreate` convertía en una firma nueva por cada
        // variante escrita.
        'user_id' => 'required|integer|exists:users,id|unique:kilkana_authors,user_id',
    ];

    protected array $validationRulesUpdate = [
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
        // que poder arreglarla), pero sigue siendo 1:1 contra el resto de firmas.
        'user_id' => 'sometimes|required|integer|exists:users,id|unique:kilkana_authors,user_id,{id}',
    ];
}
