<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Kilkana\Models\SnkKilkanaTagModel;

#[Group('Tags')]
class SnkKilkanaTagsController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaTagModel::class;

    protected string $indexPermission = 'index tags';

    protected string $showPermission = 'show tags';

    protected string $storePermission = 'create tags';

    protected string $updatePermission = 'update tags';

    protected string $destroyPermission = 'delete tags';

    protected array $validationRulesStore = [
        'name' => 'required|string|max:'.SnkKilkanaTagModel::NAME_MAX,
        'slug' => 'nullable|string|max:'.SnkKilkanaTagModel::SLUG_MAX.'|unique:kilkana_tags,slug',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'sometimes|required|string|max:'.SnkKilkanaTagModel::NAME_MAX,
        'slug' => 'nullable|string|max:'.SnkKilkanaTagModel::SLUG_MAX.'|unique:kilkana_tags,slug,{id}',
    ];
}
