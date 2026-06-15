<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Commander\Models\ClientModel;

#[Group('Clients')]
class CommanderClientsController extends CoreBaseApiController
{
    protected string $modelClass = ClientModel::class;

    protected string $indexPermission = 'index clients';
    protected string $showPermission = 'show clients';
    protected string $storePermission = 'create clients';
    protected string $updatePermission = 'update clients';
    protected string $destroyPermission = 'delete clients';

    protected array $allowedWith = ['deployments', 'subscriptions', 'invoices'];

    protected array $validationRulesStore = [
        'id' => 'required|string|max:64|unique:commander_clients,id',
        'name' => 'required|string|max:255',
        'company' => 'nullable|string|max:255',
        'email' => 'required|email|max:255',
        'phone' => 'nullable|string|max:50',
        'notes' => 'nullable|string',
        'status' => 'nullable|in:active,inactive',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'nullable|string|max:255',
        'company' => 'nullable|string|max:255',
        'email' => 'nullable|email|max:255',
        'phone' => 'nullable|string|max:50',
        'notes' => 'nullable|string',
        'status' => 'nullable|in:active,inactive',
    ];
}
