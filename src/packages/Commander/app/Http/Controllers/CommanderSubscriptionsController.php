<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Commander\Models\CommanderSubscriptionModel;

#[Group('Subscriptions')]
class CommanderSubscriptionsController extends CoreBaseApiController
{
    protected string $modelClass = CommanderSubscriptionModel::class;

    protected string $indexPermission = 'index subscriptions';
    protected string $showPermission = 'show subscriptions';
    protected string $storePermission = 'create subscriptions';
    protected string $updatePermission = 'update subscriptions';
    protected string $destroyPermission = 'delete subscriptions';

    protected array $allowedWith = ['client', 'package'];

    protected array $validationRulesStore = [
        'client_id' => 'required|integer|exists:commander_clients,id',
        'package_id' => 'required|integer|exists:commander_packages,id',
        'plan' => 'required|string|max:255',
        'price_monthly' => 'required|numeric|min:0',
        'billing_status' => 'nullable|in:paid,pending,overdue',
        'auto_suspend' => 'nullable|boolean',
    ];

    protected array $validationRulesUpdate = [
        'plan' => 'nullable|string|max:255',
        'price_monthly' => 'nullable|numeric|min:0',
        'billing_status' => 'nullable|in:paid,pending,overdue',
        'auto_suspend' => 'nullable|boolean',
    ];
}
