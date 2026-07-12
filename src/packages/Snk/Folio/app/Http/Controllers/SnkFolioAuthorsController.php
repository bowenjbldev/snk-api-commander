<?php

namespace Packages\Snk\Folio\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Folio\Models\SnkFolioAuthorModel;

#[Group('Authors')]
class SnkFolioAuthorsController extends CoreBaseApiController
{
    protected string $modelClass = SnkFolioAuthorModel::class;

    protected string $indexPermission = 'index authors';

    protected string $showPermission = 'show authors';

    protected string $storePermission = 'create authors';

    protected string $updatePermission = 'update authors';

    protected string $destroyPermission = 'delete authors';

    protected array $validationRulesStore = [
        'name' => 'required|string|max:255',
        'slug' => 'nullable|string|max:255|unique:folio_authors,slug',
        'bio' => 'nullable|string',
        'avatar_url' => 'nullable|string|max:2048',
        'user_id' => 'nullable|integer|exists:users,id',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'sometimes|required|string|max:255',
        'slug' => 'nullable|string|max:255|unique:folio_authors,slug,{id}',
        'bio' => 'nullable|string',
        'avatar_url' => 'nullable|string|max:2048',
        'user_id' => 'nullable|integer|exists:users,id',
    ];
}
