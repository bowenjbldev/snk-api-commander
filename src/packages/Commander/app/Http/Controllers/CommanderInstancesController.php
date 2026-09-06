<?php

namespace Packages\Commander\Http\Controllers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Packages\Commander\Jobs\CommanderDeprovisionInstanceJob;
use Packages\Commander\Jobs\CommanderProvisionInstanceJob;
use Packages\Commander\Models\CommanderActivityEventModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Commander\Models\CommanderUsageRollupModel;
use Packages\Commander\Services\CommanderApplicationRegistryService;
use Packages\Core\Enums\CoreServiceStatusEnum;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Services\CoreInstanceStateService;

#[Group('Instances')]
class CommanderInstancesController extends CoreBaseApiController
{
    private const CONTROL_PERMISSION = 'control instances';

    protected string $modelClass = CommanderInstanceModel::class;

    protected string $indexPermission = 'index instances';

    protected string $showPermission = 'show instances';

    protected string $storePermission = 'create instances';

    protected string $updatePermission = 'update instances';

    protected string $destroyPermission = 'delete instances';

    protected array $allowedIncludes = [
        'client', 'client.account', 'application', 'subscriptionItem',
        'subscriptionItem.subscription', 'subscriptionItem.plan', 'quotas.metric',
    ];

    public function __construct(
        private readonly CoreInstanceStateService $state,
        private readonly CommanderApplicationRegistryService $registry,
    ) {}

    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $query = $this->queryBuilder(CommanderInstanceModel::query()->with(['client', 'application', 'subscriptionItem.plan']));
        if (request()->filled('application_id')) {
            $query->where('application_id', request()->integer('application_id'));
        }
        if (request()->filled('account_id')) {
            $query->whereHas('client', fn ($q) => $q->where('account_id', request()->integer('account_id')));
        }

        return response()->json($query->orderByDesc('id')->get());
    }

    public function suspend(int $instance, Request $request): JsonResponse
    {
        return $this->changeState($instance, CoreServiceStatusEnum::Suspended, $request);
    }

    public function grace(int $instance, Request $request): JsonResponse
    {
        return $this->changeState($instance, CoreServiceStatusEnum::Grace, $request);
    }

    public function resume(int $instance, Request $request): JsonResponse
    {
        return $this->changeState($instance, CoreServiceStatusEnum::Active, $request);
    }

    public function changePlan(int $instance, Request $request): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }

        $data = $request->validate(['plan_id' => 'required|integer|exists:commander_plans,id']);
        $record = CommanderInstanceModel::query()->with('subscriptionItem')->find($instance);
        $plan = CommanderPlanModel::query()->with(['limits', 'application.metrics'])->find($data['plan_id']);
        if ($record === null) {
            return response()->json(['error' => 'Instance not found'], 404);
        }
        if ($record->subscriptionItem === null) {
            return response()->json(['error' => 'Instance has no subscription item.'], 409);
        }
        if ($plan === null || $plan->application_id !== $record->application_id || $plan->status !== 'active') {
            return response()->json(['error' => 'Plan does not belong to this active application.'], 422);
        }

        DB::connection('commander')->transaction(function () use ($record, $plan): void {
            $record->subscriptionItem()->update(['plan_id' => $plan->id]);
            $limits = $plan->limits->keyBy('metric_id');
            $activeMetricIds = $plan->application->metrics->where('status', 'active')->pluck('id');
            CommanderQuotaModel::query()
                ->where('instance_id', $record->id)
                ->whereNotIn('metric_id', $activeMetricIds)
                ->update(['limit_value' => null, 'action' => 'none']);
            foreach ($plan->application->metrics->where('status', 'active') as $metric) {
                $limit = $limits->get($metric->id);
                CommanderQuotaModel::updateOrCreate(
                    ['instance_id' => $record->id, 'metric_id' => $metric->id],
                    [
                        'limit_value' => $limit?->limit_value,
                        'warning_percent' => $limit?->warning_percent ?? 80,
                        'action' => $limit?->action ?? 'none',
                        'consecutive_periods' => $limit?->consecutive_periods ?? 1,
                    ],
                );
            }
        });

        $this->recordActivity($record->id, 'plan_changed', 'plan:'.$plan->slug, null);

        return response()->json($record->fresh()->load(['subscriptionItem.plan', 'quotas.metric']));
    }

    public function retry(int $instance, Request $request): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }

        $data = $request->validate([
            'admin_email' => 'required|email|max:255',
            'admin_password' => ['required', 'string', 'max:255', Password::min(12)->letters()->numbers()],
        ]);
        $record = CommanderInstanceModel::query()->with(['client', 'application', 'subscriptionItem.subscription', 'subscriptionItem.plan'])->find($instance);
        if ($record === null) {
            return response()->json(['error' => 'Instance not found'], 404);
        }
        if (! in_array($record->provisioning_status, ['failed', 'deprovisioned'], true)) {
            return response()->json(['error' => 'Only failed or deprovisioned instances can be retried.'], 409);
        }
        if ($record->subscriptionItem === null) {
            return response()->json(['error' => 'Instance has no subscription item.'], 409);
        }
        if (! $this->registry->hasImplementation($record->client->slug, $record->application->package_name)) {
            return response()->json(['error' => 'Application code is not deployed for this client.'], 422);
        }

        $record = DB::connection('commander')->transaction(function () use ($record) {
            $locked = CommanderInstanceModel::query()
                ->with(['client', 'application', 'subscriptionItem.subscription', 'subscriptionItem.plan'])
                ->lockForUpdate()
                ->findOrFail($record->id);
            abort_unless(in_array($locked->provisioning_status, ['failed', 'deprovisioned'], true), 409, 'Instance is already being retried.');
            abort_if($locked->subscriptionItem === null, 409, 'Instance has no subscription item.');

            $item = $locked->subscriptionItem;
            $subscription = $item->subscription;
            abort_unless($locked->client->status === 'active'
                && $locked->application->status === 'active'
                && $locked->application->code_status === 'available'
                && $item->plan?->status === 'active', 422, 'Client, application or plan is inactive.');
            $subscriptionIsActive = in_array($subscription->status, ['active', 'trialing'], true)
                || ($locked->provisioning_status === 'deprovisioned'
                    && $subscription->pricing_mode === 'catalog'
                    && $subscription->status === 'canceled');
            abort_unless($subscription->account_id === $locked->client->account_id
                && $subscription->application_id === $locked->application_id
                && $subscriptionIsActive, 422, 'Subscription is not valid for this instance.');
            $otherActiveItems = $subscription->items()
                ->where('id', '!=', $item->id)
                ->where('status', 'active')
                ->count();
            abort_if($subscription->max_instances !== null
                && $otherActiveItems >= $subscription->max_instances, 422, 'Subscription has no available instance slots.');

            $locked->forceFill([
                'database_username' => $locked->database_username ?: 'tenant_'.$locked->id,
                'database_password' => $locked->database_password ?: Str::random(48),
                'provisioning_status' => 'pending',
                'requested_at' => now(),
                'deprovisioned_at' => null,
                'last_error' => null,
            ])->save();
            $item->forceFill(['status' => 'active', 'ended_at' => null])->save();
            if ($subscription->pricing_mode === 'catalog') {
                $subscription->forceFill(['status' => 'active', 'ended_at' => null])->save();
            }

            return $locked;
        });

        CommanderProvisionInstanceJob::dispatch(
            $record->id,
            $record->client->slug,
            $record->application->package_name,
            $data['admin_email'],
            Crypt::encryptString($data['admin_password']),
        )->afterCommit();

        return response()->json($record->fresh(), 202);
    }

    public function usage(int $instance, Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }
        if (! CommanderInstanceModel::query()->whereKey($instance)->exists()) {
            return response()->json(['error' => 'Instance not found'], 404);
        }

        $data = $request->validate(['period' => 'nullable|in:hour,day', 'hours' => 'nullable|integer|min:1|max:8784']);
        $rows = CommanderUsageRollupModel::query()
            ->where('instance_id', $instance)
            ->where('period', $data['period'] ?? 'hour')
            ->where('period_start', '>=', CarbonImmutable::now()->subHours($data['hours'] ?? 48))
            ->orderBy('period_start')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function logs(int $instance, Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }
        if (! CommanderInstanceModel::query()->whereKey($instance)->exists()) {
            return response()->json(['error' => 'Instance not found'], 404);
        }

        $data = $request->validate(['limit' => 'nullable|integer|min:1|max:500']);
        $rows = CommanderRequestLogModel::query()
            ->where('instance_id', $instance)
            ->latest()
            ->limit($data['limit'] ?? 100)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function destroy($instance = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $record = CommanderInstanceModel::query()->find($instance);
        if ($record === null) {
            return response()->json(['error' => 'Instance not found'], 404);
        }
        if ($record->provisioning_status === 'deprovisioning') {
            return response()->json(['error' => 'Instance is already being deprovisioned.'], 409);
        }
        if ($record->provisioning_status === 'deprovisioned') {
            return response()->json(['error' => 'Instance is already deprovisioned.'], 409);
        }

        $record->forceFill(['provisioning_status' => 'deprovisioning', 'changed_at' => now()])->save();
        CommanderDeprovisionInstanceJob::dispatch($record->id);

        return response()->json(['instance_id' => $record->id, 'status' => 'deprovisioning'], 202);
    }

    private function changeState(int $instance, CoreServiceStatusEnum $status, Request $request): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }
        $record = CommanderInstanceModel::query()->find($instance);
        if ($record === null) {
            return response()->json(['error' => 'Instance not found'], 404);
        }
        if ($record->provisioning_status !== 'ready') {
            return response()->json(['error' => 'Only ready instances have a service state.'], 409);
        }

        $data = $request->validate(['reason' => 'nullable|string|max:100', 'message' => 'nullable|string|max:2000', 'hours' => 'nullable|integer|min:1|max:720']);
        $graceUntil = $status === CoreServiceStatusEnum::Grace
            ? CarbonImmutable::now()->addHours($data['hours'] ?? (int) config('core.quotas.grace_hours', 72))
            : null;
        $state = $this->state->set(
            $status,
            $data['reason'] ?? ($status === CoreServiceStatusEnum::Active ? null : 'manual'),
            $data['message'] ?? null,
            $graceUntil,
            $instance,
        );
        $this->recordActivity($instance, $status->value, $state->reason, $state->message);

        return response()->json(CommanderInstanceModel::query()->find($instance));
    }

    private function recordActivity(int $instanceId, string $kind, ?string $reason, ?string $message): void
    {
        CommanderActivityEventModel::create(compact('reason', 'message') + [
            'instance_id' => $instanceId,
            'invoice_id' => null,
            'kind' => $kind,
        ]);
    }
}
