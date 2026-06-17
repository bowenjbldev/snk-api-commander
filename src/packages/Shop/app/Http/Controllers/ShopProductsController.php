<?php

namespace Packages\Shop\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Shop\Models\ProductModel;

#[Group('Products')]
class ShopProductsController extends CoreBaseApiController
{
    protected string $modelClass = ProductModel::class;

    protected string $indexPermission = 'index products';
    protected string $showPermission = 'show products';
    protected string $storePermission = 'create products';
    protected string $updatePermission = 'update products';
    protected string $destroyPermission = 'delete products';

    protected array $validationRulesStore = [
        'name' => 'required|string|max:255',
        'sku' => 'required|string|max:64|unique:shop_products,sku',
        'price' => 'required|numeric|min:0',
        'stock' => 'nullable|integer|min:0',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'nullable|string|max:255',
        'sku' => 'nullable|string|max:64|unique:shop_products,sku,{id}',
        'price' => 'nullable|numeric|min:0',
        'stock' => 'nullable|integer|min:0',
    ];
}
