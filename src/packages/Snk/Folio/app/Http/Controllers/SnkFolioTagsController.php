<?php

namespace Packages\Snk\Folio\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Folio\Models\SnkFolioTagModel;

#[Group('Tags')]
class SnkFolioTagsController extends CoreBaseApiController
{
    protected string $modelClass = SnkFolioTagModel::class;

    protected string $indexPermission = 'index tags';

    protected string $showPermission = 'show tags';

    protected string $storePermission = 'create tags';

    protected string $updatePermission = 'update tags';

    protected string $destroyPermission = 'delete tags';

    protected array $validationRulesStore = [
        'name' => 'required|string|max:64',
        'slug' => 'nullable|string|max:64|unique:folio_tags,slug',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'sometimes|required|string|max:64',
        'slug' => 'nullable|string|max:64|unique:folio_tags,slug,{id}',
    ];
}
