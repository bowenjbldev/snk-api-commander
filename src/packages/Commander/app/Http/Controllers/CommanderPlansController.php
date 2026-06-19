<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Plans')]
class CommanderPlansController extends CoreBaseApiController
{
    protected string $modelClass = CommanderPlanModel::class;

    protected string $indexPermission = 'index plans';
    protected string $showPermission = 'show plans';
    protected string $storePermission = 'create plans';
    protected string $updatePermission = 'update plans';
    protected string $destroyPermission = 'delete plans';

    protected array $validationRulesStore = [
        'id' => 'required|string|max:64|unique:commander_plans,id',
        'package' => 'required|string|max:255',
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',
        'price_monthly' => 'required|numeric|min:0',
        'q_requests_day' => 'nullable|integer|min:0',
        'q_disk_db_mb' => 'nullable|integer|min:0',
        'q_disk_storage_mb' => 'nullable|integer|min:0',
        'q_users_total' => 'nullable|integer|min:0',
        'status' => 'nullable|in:active,inactive',
    ];

    protected array $validationRulesUpdate = [
        'package' => 'nullable|string|max:255',
        'name' => 'nullable|string|max:255',
        'description' => 'nullable|string',
        'price_monthly' => 'nullable|numeric|min:0',
        'q_requests_day' => 'nullable|integer|min:0',
        'q_disk_db_mb' => 'nullable|integer|min:0',
        'q_disk_storage_mb' => 'nullable|integer|min:0',
        'q_users_total' => 'nullable|integer|min:0',
        'status' => 'nullable|in:active,inactive',
    ];
}
