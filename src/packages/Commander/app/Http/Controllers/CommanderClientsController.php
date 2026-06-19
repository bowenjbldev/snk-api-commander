<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Commander\Models\CommanderClientModel;

#[Group('Clients')]
class CommanderClientsController extends CoreBaseApiController
{
    protected string $modelClass = CommanderClientModel::class;

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
        // Billing
        'billing_email' => 'nullable|email|max:255',
        'tax_id' => 'nullable|string|max:64',
        'address_line1' => 'nullable|string|max:255',
        'address_line2' => 'nullable|string|max:255',
        'city' => 'nullable|string|max:128',
        'postal_code' => 'nullable|string|max:32',
        'country' => 'nullable|string|size:2',
        'payment_terms' => 'nullable|in:prepaid,net_15,net_30,net_60',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'nullable|string|max:255',
        'company' => 'nullable|string|max:255',
        'email' => 'nullable|email|max:255',
        'phone' => 'nullable|string|max:50',
        'notes' => 'nullable|string',
        'status' => 'nullable|in:active,inactive',
        // Billing
        'billing_email' => 'nullable|email|max:255',
        'tax_id' => 'nullable|string|max:64',
        'address_line1' => 'nullable|string|max:255',
        'address_line2' => 'nullable|string|max:255',
        'city' => 'nullable|string|max:128',
        'postal_code' => 'nullable|string|max:32',
        'country' => 'nullable|string|size:2',
        'payment_terms' => 'nullable|in:prepaid,net_15,net_30,net_60',
    ];
}
