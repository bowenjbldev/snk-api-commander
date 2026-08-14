<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Packages\Commander\Models\CommanderApplicationMetricModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Plans')]
class CommanderPlansController extends CoreBaseApiController
{
    protected string $modelClass = CommanderPlanModel::class;

    protected string $indexPermission = 'index plans';

    protected string $showPermission = 'show plans';

    protected string $storePermission = 'create plans';

    protected string $updatePermission = 'update plans';

    protected string $destroyPermission = 'delete plans';

    protected array $allowedWith = ['application', 'limits', 'limits.metric'];

    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        return response()->json($this->applyWith(
            CommanderPlanModel::query()->with(['application', 'limits.metric'])
        )->orderBy('application_id')->orderBy('price_monthly')->get());
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $data = $this->validated($request);
        $this->guardMetrics($data['application_id'], $data['limits'] ?? []);
        $plan = DB::connection('commander')->transaction(function () use ($data) {
            $limits = $data['limits'] ?? [];
            unset($data['limits']);
            $plan = CommanderPlanModel::create($data);
            $plan->limits()->createMany($limits);

            return $plan;
        });

        return response()->json($plan->load(['application', 'limits.metric']), 201);
    }

    public function update(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $plan = CommanderPlanModel::query()->find($id);
        if ($plan === null) {
            return response()->json(['error' => 'Plan not found'], 404);
        }
        $data = $this->validated($request, $plan);
        $applicationId = $data['application_id'] ?? $plan->application_id;
        if (isset($data['limits'])) {
            $this->guardMetrics($applicationId, $data['limits']);
        }

        DB::connection('commander')->transaction(function () use ($plan, $data): void {
            $limits = $data['limits'] ?? null;
            unset($data['limits']);
            $plan->fill($data)->save();
            if ($limits !== null) {
                $plan->limits()->delete();
                $plan->limits()->createMany($limits);
            }
        });

        return response()->json($plan->fresh()->load(['application', 'limits.metric']));
    }

    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $plan = CommanderPlanModel::query()->find($id);
        if ($plan === null) {
            return response()->json(['error' => 'Plan not found'], 404);
        }
        if ($plan->subscriptionItems()->exists()) {
            return response()->json(['error' => 'Plan is assigned to an instance; mark it inactive instead.'], 409);
        }
        $plan->delete();

        return response()->json(['id' => $plan->id]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CommanderPlanModel $plan = null): array
    {
        $applicationId = $request->integer('application_id') ?: $plan?->application_id;

        return $request->validate([
            'application_id' => [$plan ? 'prohibited' : 'required', 'integer', 'exists:commander_applications,id'],
            'slug' => [
                $plan ? 'sometimes' : 'required', 'string', 'max:64',
                Rule::unique('commander_plans', 'slug')->where('application_id', $applicationId)->ignore($plan?->id),
            ],
            'name' => [$plan ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => 'nullable|string',
            'price_monthly' => [$plan ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'status' => 'sometimes|required|in:active,inactive',
            'limits' => 'sometimes|array',
            'limits.*.metric_id' => 'required|integer|distinct|exists:commander_application_metrics,id',
            'limits.*.limit_value' => 'nullable|integer|min:0',
            'limits.*.warning_percent' => 'sometimes|integer|min:1|max:100',
            'limits.*.action' => 'sometimes|in:none,warn,suspend,recommend',
            'limits.*.consecutive_periods' => 'sometimes|integer|min:1|max:12',
        ]);
    }

    /** @param array<int, array<string, mixed>> $limits */
    private function guardMetrics(int $applicationId, array $limits): void
    {
        $metricIds = collect($limits)->pluck('metric_id')->filter()->values();
        if ($metricIds->isEmpty()) {
            return;
        }

        $count = CommanderApplicationMetricModel::query()
            ->where('application_id', $applicationId)
            ->whereKey($metricIds)
            ->count();
        if ($count !== $metricIds->count()) {
            throw ValidationException::withMessages([
                'limits' => ['Every metric must belong to the selected application.'],
            ]);
        }
    }
}
