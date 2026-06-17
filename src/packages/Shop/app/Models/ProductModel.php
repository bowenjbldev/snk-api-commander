<?php

namespace Packages\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductModel extends Model
{
    protected $table = 'shop_products';

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
    ];

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(OrderModel::class, 'shop_order_product', 'product_id', 'order_id')
            ->withPivot(['qty', 'unit_price']);
    }
}
