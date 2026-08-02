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

    protected array $allowedWith = ['account', 'packages', 'subscriptions', 'invoices'];

    protected array $validationRulesStore = [
        'account_id' => 'sometimes|required|integer|exists:commander_accounts,id',
        'slug' => 'required|string|max:63|regex:/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/|not_in:api,commander,www|unique:commander_clients,slug',
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
        'account_id' => 'sometimes|required|integer|exists:commander_accounts,id',
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
