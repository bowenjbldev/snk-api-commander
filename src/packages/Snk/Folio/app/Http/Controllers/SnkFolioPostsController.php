<?php

namespace Packages\Snk\Folio\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Folio\Models\SnkFolioPostModel;

/**
 * CRUD de articulos del periodico (contrato Post de snk-news, en snake_case).
 * catLabel/hue/time NO se guardan: el cliente los deriva de cat/published_at.
 */
#[Group('Posts')]
class SnkFolioPostsController extends CoreBaseApiController
{
    protected string $modelClass = SnkFolioPostModel::class;

    protected string $indexPermission = 'index posts';

    protected string $showPermission = 'show posts';

    protected string $storePermission = 'create posts';

    protected string $updatePermission = 'update posts';

    protected string $destroyPermission = 'delete posts';

    protected array $validationRulesStore = [
        // Mismo regex que normalizePost en snk-news: el cliente genera el id (u{ts36}).
        'id' => 'required|string|max:128|regex:/^[A-Za-z][A-Za-z0-9_-]{0,127}$/|unique:folio_posts,id',
        'cat' => 'required|string|max:32',
        'title' => 'required|string|max:500',
        'excerpt' => 'required|string',
        'author' => 'required|string|max:255',
        'published_at' => 'required|date',
        'reads' => 'nullable|integer|min:0',
        'image' => 'nullable|string|max:2048',
        'status' => 'required|in:published,draft,scheduled',
        'body' => 'nullable|string',
        'body_blocks' => 'nullable|array',
        'body_blocks.*.id' => 'required|string|max:128',
        'body_blocks.*.type' => 'required|in:texto,cita,imagen,galeria,video,muro,separador',
        'body_blocks.*.config' => 'nullable|array',
        'tags' => 'nullable|array',
        'tags.*' => 'string|max:64',
        'slug' => 'nullable|string|max:255',
        'featured' => 'nullable|boolean',
        'scheduled_for' => 'nullable|string|max:32',
        'seo_title' => 'nullable|string|max:500',
        'seo_description' => 'nullable|string',
    ];

    protected array $validationRulesUpdate = [
        'cat' => 'sometimes|required|string|max:32',
        'title' => 'sometimes|required|string|max:500',
        'excerpt' => 'sometimes|required|string',
        'author' => 'sometimes|required|string|max:255',
        'published_at' => 'sometimes|required|date',
        'reads' => 'nullable|integer|min:0',
        'image' => 'nullable|string|max:2048',
        'status' => 'sometimes|required|in:published,draft,scheduled',
        'body' => 'nullable|string',
        'body_blocks' => 'nullable|array',
        'body_blocks.*.id' => 'required|string|max:128',
        'body_blocks.*.type' => 'required|in:texto,cita,imagen,galeria,video,muro,separador',
        'body_blocks.*.config' => 'nullable|array',
        'tags' => 'nullable|array',
        'tags.*' => 'string|max:64',
        'slug' => 'nullable|string|max:255',
        'featured' => 'nullable|boolean',
        'scheduled_for' => 'nullable|string|max:32',
        'seo_title' => 'nullable|string|max:500',
        'seo_description' => 'nullable|string',
    ];
}
