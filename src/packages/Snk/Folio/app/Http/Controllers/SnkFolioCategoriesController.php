<?php

namespace Packages\Snk\Folio\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Folio\Models\SnkFolioCategoryModel;

#[Group('Categories')]
class SnkFolioCategoriesController extends CoreBaseApiController
{
    protected string $modelClass = SnkFolioCategoryModel::class;

    protected string $indexPermission = 'index categories';

    protected string $showPermission = 'show categories';

    protected string $storePermission = 'create categories';

    protected string $updatePermission = 'update categories';

    protected string $destroyPermission = 'delete categories';

    protected array $validationRulesStore = [
        'name' => 'required|string|max:255',
        'slug' => 'nullable|string|max:64|unique:folio_categories,slug',
        'hue' => 'nullable|integer|min:0|max:360',
        'parent_id' => 'nullable|integer|exists:folio_categories,id',
        'description' => 'nullable|string',
        'position' => 'nullable|integer|min:0|max:65535',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'sometimes|required|string|max:255',
        'slug' => 'nullable|string|max:64|unique:folio_categories,slug,{id}',
        'hue' => 'nullable|integer|min:0|max:360',
        // not_in:{id}: una sección no puede ser su propio padre (ciclos profundos: pendiente si llega UI de árbol)
        'parent_id' => 'nullable|integer|exists:folio_categories,id|not_in:{id}',
        'description' => 'nullable|string',
        'position' => 'nullable|integer|min:0|max:65535',
    ];
}
