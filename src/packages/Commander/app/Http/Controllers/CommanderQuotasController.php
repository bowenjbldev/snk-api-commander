<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Packages\Commander\Models\CommanderApplicationMetricModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

/**
 * Cada cuota pertenece a una instancia o a una suscripción. El motor mide y aplica la misma fila.
 */
#[Group('Quotas')]
class CommanderQuotasController extends CoreBaseApiController
{
    protected string $modelClass = CommanderQuotaModel::class;

    protected string $indexPermission = 'index quotas';

    protected string $showPermission = 'show quotas';

    protected string $storePermission = 'create quotas';

    protected string $updatePermission = 'update quotas';

    protected string $destroyPermission = 'delete quotas';

    protected array $allowedIncludes = [
        'instance', 'instance.client', 'instance.application',
        'subscription', 'subscription.account', 'subscription.application',
        'metric', 'measurements',
    ];

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $data = $request->validate([
            'subscription_id' => 'required|integer|exists:commander_subscriptions,id',
            'metric_id' => 'required|integer|exists:commander_application_metrics,id',
            'limit_value' => 'nullable|integer|min:0',
            'warning_percent' => 'sometimes|integer|min:1|max:100',
            'action' => 'sometimes|in:none,warn,suspend,recommend',
            'consecutive_periods' => 'sometimes|integer|min:1|max:12',
        ]);
        $subscription = CommanderSubscriptionModel::query()->findOrFail($data['subscription_id']);
        $metric = CommanderApplicationMetricModel::query()->findOrFail($data['metric_id']);
        if ($subscription->application_id !== $metric->application_id) {
            throw ValidationException::withMessages([
                'metric_id' => ['Metric must belong to the subscription application.'],
            ]);
        }
        if ($subscription->quotas()->where('metric_id', $metric->id)->exists()) {
            throw ValidationException::withMessages([
                'metric_id' => ['The subscription already has this quota.'],
            ]);
        }

        $quota = CommanderQuotaModel::create(['instance_id' => null, ...$data]);

        return response()->json($quota->load(['subscription', 'metric']), 201);
    }

    protected array $validationRulesUpdate = [
        'limit_value' => 'nullable|integer|min:0',
        'warning_percent' => 'sometimes|integer|min:1|max:100',
        'action' => 'sometimes|in:none,warn,suspend,recommend',
        'consecutive_periods' => 'sometimes|integer|min:1|max:12',
    ];
}
