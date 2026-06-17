<?php

namespace Packages\Shop\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Shop\Models\OrderModel;
use Packages\Shop\Models\ProductModel;

#[Group('Orders')]
class ShopOrdersController extends CoreBaseApiController
{
    protected string $modelClass = OrderModel::class;

    protected string $indexPermission = 'index orders';
    protected string $showPermission = 'show orders';
    protected string $storePermission = 'create orders';
    protected string $updatePermission = 'update orders';
    protected string $destroyPermission = 'delete orders';

    protected array $allowedWith = ['customer', 'products'];

    protected array $validationRulesUpdate = [
        'status' => 'nullable|in:pending,paid,shipped,cancelled',
    ];

    /**
     * Create an order, optionally attaching line items. The order total is computed server-side
     * from each product's current price, so callers only send product_id + qty.
     */
    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $data = $request->validate([
            'customer_id' => 'required|integer|exists:shop_customers,id',
            'status' => 'nullable|in:pending,paid,shipped,cancelled',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|integer|exists:shop_products,id',
            'items.*.qty' => 'required_with:items|integer|min:1',
        ]);

        // Merge duplicate product_ids (sum qty) so a repeated line item doesn't collide on the
        // pivot's unique (order_id, product_id) constraint.
        $quantities = [];
        foreach ($data['items'] ?? [] as $item) {
            $pid = (int) $item['product_id'];
            $quantities[$pid] = ($quantities[$pid] ?? 0) + (int) $item['qty'];
        }

        $order = DB::transaction(function () use ($data, $quantities) {
            $order = OrderModel::create([
                'customer_id' => $data['customer_id'],
                'status' => $data['status'] ?? 'pending',
                'total' => 0,
            ]);

            $total = 0.0;
            foreach ($quantities as $pid => $qty) {
                $unitPrice = (float) ProductModel::findOrFail($pid)->price;
                $order->products()->attach($pid, ['qty' => $qty, 'unit_price' => $unitPrice]);
                $total += $unitPrice * $qty;
            }

            $order->update(['total' => $total]);

            return $order;
        });

        return response()->json($order->load(['customer', 'products']), 201);
    }
}
