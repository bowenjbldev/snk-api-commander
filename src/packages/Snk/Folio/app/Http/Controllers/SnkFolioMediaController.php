<?php

namespace Packages\Snk\Folio\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Folio\Models\SnkFolioMediaModel;

#[Group('Media')]
class SnkFolioMediaController extends CoreBaseApiController
{
    protected string $modelClass = SnkFolioMediaModel::class;

    protected string $indexPermission = 'index media';

    protected string $showPermission = 'show media';

    protected string $storePermission = 'create media';

    protected string $updatePermission = 'update media';

    protected string $destroyPermission = 'delete media';

    protected array $validationRulesStore = [
        'url' => 'required|string|max:2048',
        'alt' => 'nullable|string|max:500',
        'caption' => 'nullable|string|max:500',
        'mime' => 'nullable|string|max:64',
        'width' => 'nullable|integer|min:0',
        'height' => 'nullable|integer|min:0',
    ];

    protected array $validationRulesUpdate = [
        'url' => 'sometimes|required|string|max:2048',
        'alt' => 'nullable|string|max:500',
        'caption' => 'nullable|string|max:500',
        'mime' => 'nullable|string|max:64',
        'width' => 'nullable|integer|min:0',
        'height' => 'nullable|integer|min:0',
    ];
}
