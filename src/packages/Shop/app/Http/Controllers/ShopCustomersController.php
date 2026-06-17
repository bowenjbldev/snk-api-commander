<?php

namespace Packages\Shop\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Shop\Models\CustomerModel;

#[Group('Customers')]
class ShopCustomersController extends CoreBaseApiController
{
    protected string $modelClass = CustomerModel::class;

    protected string $indexPermission = 'index customers';
    protected string $showPermission = 'show customers';
    protected string $storePermission = 'create customers';
    protected string $updatePermission = 'update customers';
    protected string $destroyPermission = 'delete customers';

    protected array $allowedWith = ['orders'];

    protected array $validationRulesStore = [
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255|unique:shop_customers,email',
        'phone' => 'nullable|string|max:50',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'nullable|string|max:255',
        'email' => 'nullable|email|max:255|unique:shop_customers,email,{id}',
        'phone' => 'nullable|string|max:50',
    ];
}
