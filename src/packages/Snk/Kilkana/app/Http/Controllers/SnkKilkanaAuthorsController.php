<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
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

    protected array $validationRulesStore = [
        // El nombre NO se manda: se publica el de la cuenta. `alias` solo cuando firma con
        // seudónimo, y entonces es lo que se ve.
        'alias' => 'nullable|string|max:255',
        // Cargo publicable. No es `users.company`: aquella es la organización del CRM y no
        // sale al público; esta es la que firma bajo el nombre.
        'job_title' => 'nullable|string|max:255',
        'slug' => 'nullable|string|max:255|unique:kilkana_authors,slug',
        'bio' => 'nullable|string',
        'avatar_media_id' => 'nullable|integer|exists:kilkana_media,id',
        'website' => 'nullable|string|max:2048',
        'socials' => 'nullable|array',
        'socials.*' => 'string|max:2048',
        // IDENTIDAD de la firma: la cuenta. Obligatoria y 1:1 — el correo del periodista no
        // se duplica aquí, se lee de `users`. Antes esto era opcional y la identidad de
        // facto era el nombre, que `firstOrCreate` convertía en una firma nueva por cada
        // variante escrita.
        'user_id' => 'required|integer|exists:users,id|unique:kilkana_authors,user_id',
    ];

    protected array $validationRulesUpdate = [
        'alias' => 'nullable|string|max:255',
        'job_title' => 'nullable|string|max:255',
        'slug' => 'nullable|string|max:255|unique:kilkana_authors,slug,{id}',
        'bio' => 'nullable|string',
        'avatar_media_id' => 'nullable|integer|exists:kilkana_media,id',
        'website' => 'nullable|string|max:2048',
        'socials' => 'nullable|array',
        'socials.*' => 'string|max:2048',
        // Cambiar de cuenta es cambiar de persona: se admite (una firma mal enlazada hay
        // que poder arreglarla), pero sigue siendo 1:1 contra el resto de firmas.
        'user_id' => 'sometimes|required|integer|exists:users,id|unique:kilkana_authors,user_id,{id}',
    ];
}
