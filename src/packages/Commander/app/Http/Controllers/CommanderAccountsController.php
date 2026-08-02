<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Accounts')]
class CommanderAccountsController extends CoreBaseApiController
{
    protected string $modelClass = CommanderAccountModel::class;

    protected string $indexPermission = 'index accounts';

    protected string $showPermission = 'show accounts';

    protected string $storePermission = 'create accounts';

    protected string $updatePermission = 'update accounts';

    protected string $destroyPermission = 'delete accounts';

    protected array $allowedWith = ['clients', 'clients.packages'];

    protected array $validationRulesStore = [
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
        'billing_email' => 'nullable|email|max:255',
        'phone' => 'nullable|string|max:50',
        'tax_id' => 'nullable|string|max:64',
        'payment_terms' => 'nullable|in:prepaid,net_15,net_30,net_60',
        'address_line1' => 'nullable|string|max:255',
        'address_line2' => 'nullable|string|max:255',
        'city' => 'nullable|string|max:128',
        'postal_code' => 'nullable|string|max:32',
        'country' => 'nullable|string|size:2',
        'notes' => 'nullable|string',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'nullable|string|max:255',
        'email' => 'nullable|email|max:255',
        'billing_email' => 'nullable|email|max:255',
        'phone' => 'nullable|string|max:50',
        'tax_id' => 'nullable|string|max:64',
        'payment_terms' => 'nullable|in:prepaid,net_15,net_30,net_60',
        'address_line1' => 'nullable|string|max:255',
        'address_line2' => 'nullable|string|max:255',
        'city' => 'nullable|string|max:128',
        'postal_code' => 'nullable|string|max:32',
        'country' => 'nullable|string|size:2',
        'notes' => 'nullable|string',
    ];
}
