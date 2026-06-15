<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Commander\Models\SubscriptionModel;

#[Group('Subscriptions')]
class CommanderSubscriptionsController extends CoreBaseApiController
{
    protected string $modelClass = SubscriptionModel::class;

    protected string $indexPermission = 'index subscriptions';
    protected string $showPermission = 'show subscriptions';
    protected string $storePermission = 'create subscriptions';
    protected string $updatePermission = 'update subscriptions';
    protected string $destroyPermission = 'delete subscriptions';

    protected array $allowedWith = ['client', 'deployment'];

    protected array $validationRulesStore = [
        'id' => 'required|string|max:64|unique:commander_subscriptions,id',
        'client_id' => 'required|string|exists:commander_clients,id',
        'deployment_id' => 'required|string|exists:commander_deployments,id',
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
