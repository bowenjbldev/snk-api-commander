<?php

namespace Packages\Cms\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Cms\Models\QuotaModel;

#[Group('Quotas')]
class CmsQuotasController extends CoreBaseApiController
{
    protected string $modelClass = QuotaModel::class;

    protected string $indexPermission = 'index quotas';
    protected string $showPermission = 'show quotas';
    protected string $storePermission = 'create quotas';
    protected string $updatePermission = 'update quotas';
    protected string $destroyPermission = 'delete quotas';

    protected array $allowedWith = ['deployment'];

    protected array $validationRulesUpdate = [
        'limit_value' => 'nullable|integer|min:0',
        'on_exceed' => 'nullable|in:none,warn,suspend',
    ];
}
