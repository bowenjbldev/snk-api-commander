<?php

namespace Packages\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrderModel extends Model
{
    protected $table = 'shop_orders';

    protected $guarded = [];

    protected $casts = [
        'total' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerModel::class, 'customer_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(ProductModel::class, 'shop_order_product', 'order_id', 'product_id')
            ->withPivot(['qty', 'unit_price']);
    }
}
