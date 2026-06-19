<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Commander\Models\CommanderQuotaModel;

#[Group('Quotas')]
class CommanderQuotasController extends CoreBaseApiController
{
    protected string $modelClass = CommanderQuotaModel::class;

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
