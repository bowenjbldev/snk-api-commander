<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Clients')]
class CommanderClientsController extends CoreBaseApiController
{
    protected string $modelClass = CommanderClientModel::class;

    protected string $indexPermission = 'index clients';

    protected string $showPermission = 'show clients';

    protected string $storePermission = 'create clients';

    protected string $updatePermission = 'update clients';

    protected string $destroyPermission = 'delete clients';

    protected array $allowedWith = ['account', 'instances', 'instances.application', 'instances.subscriptionItem.plan'];

    protected array $validationRulesStore = [
        'account_id' => 'required|integer|exists:commander_accounts,id',
        'slug' => 'required|string|max:63|regex:/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/|not_in:api,commander,www|unique:commander_clients,slug',
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
        'notes' => 'nullable|string',
        'status' => 'nullable|in:active,inactive',
    ];

    protected array $validationRulesUpdate = [
        'account_id' => 'prohibited',
        'name' => 'nullable|string|max:255',
        'email' => 'nullable|email|max:255',
        'notes' => 'nullable|string',
        'status' => 'nullable|in:active,inactive',
    ];
}
