<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Subscriptions')]
class CommanderSubscriptionsController extends CoreBaseApiController
{
    protected string $modelClass = CommanderSubscriptionModel::class;

    protected string $indexPermission = 'index subscriptions';

    protected string $showPermission = 'show subscriptions';

    protected string $storePermission = 'create subscriptions';

    protected string $updatePermission = 'update subscriptions';

    protected string $destroyPermission = 'delete subscriptions';

    protected array $allowedWith = ['account', 'application', 'items', 'items.instance.client', 'items.plan', 'quotas.metric'];

    protected array $validationRulesStore = [
        'account_id' => 'required|integer|exists:commander_accounts,id',
        'application_id' => 'required|integer|exists:commander_applications,id',
        'name' => 'required|string|max:255',
        'pricing_mode' => 'required|in:catalog,negotiated',
        'monthly_amount' => 'required|numeric|min:0',
        'currency' => 'sometimes|required|string|size:3',
        'status' => 'sometimes|required|in:trialing,active,past_due,canceled',
        'max_instances' => 'nullable|integer|min:1',
        'trial_ends_at' => 'nullable|date',
        'started_at' => 'nullable|date',
        'ended_at' => 'nullable|date',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'sometimes|required|string|max:255',
        'monthly_amount' => 'sometimes|required|numeric|min:0',
        'currency' => 'sometimes|required|string|size:3',
        'status' => 'sometimes|required|in:trialing,active,past_due,canceled',
        'max_instances' => 'nullable|integer|min:1',
        'trial_ends_at' => 'nullable|date',
        'started_at' => 'nullable|date',
        'ended_at' => 'nullable|date',
    ];

    public function update(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $subscription = CommanderSubscriptionModel::query()->find($id);
        if ($subscription !== null && $request->has('max_instances')) {
            $activeItems = $subscription->items()->where('status', 'active')->count();
            $capacity = $request->input('max_instances');
            if ($capacity !== null && filter_var($capacity, FILTER_VALIDATE_INT) !== false
                && (int) $capacity < $activeItems) {
                return response()->json(['max_instances' => ['Capacity cannot be lower than active instances.']], 422);
            }
        }

        return parent::update($request, $id);
    }

    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $subscription = CommanderSubscriptionModel::query()->find($id);
        if ($subscription === null) {
            return response()->json(['error' => 'Subscription not found'], 404);
        }
        if ($subscription->items()->exists()
            || $subscription->quotas()->exists()
            || $subscription->invoiceLines()->exists()) {
            return response()->json(['error' => 'Subscription has history; cancel it instead.'], 409);
        }

        $subscription->delete();

        return response()->json(['id' => $subscription->id]);
    }
}
