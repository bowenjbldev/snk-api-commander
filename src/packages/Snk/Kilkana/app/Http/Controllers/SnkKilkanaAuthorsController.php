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

    protected array $validationRulesStore = [
        'name' => 'required|string|max:255',
        'slug' => 'nullable|string|max:255|unique:kilkana_authors,slug',
        'bio' => 'nullable|string',
        'avatar_media_id' => 'nullable|integer|exists:kilkana_media,id',
        'website' => 'nullable|string|max:2048',
        'socials' => 'nullable|array',
        'socials.*' => 'string|max:2048',
        'user_id' => 'nullable|integer|exists:users,id',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'sometimes|required|string|max:255',
        'slug' => 'nullable|string|max:255|unique:kilkana_authors,slug,{id}',
        'bio' => 'nullable|string',
        'avatar_media_id' => 'nullable|integer|exists:kilkana_media,id',
        'website' => 'nullable|string|max:2048',
        'socials' => 'nullable|array',
        'socials.*' => 'string|max:2048',
        'user_id' => 'nullable|integer|exists:users,id',
    ];
}
