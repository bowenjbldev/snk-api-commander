<?php

namespace Packages\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Core\Models\CorePackageStateModel;
use Packages\Core\Models\CoreQuotaMetricModel;
use Packages\Core\Models\CoreUsageRollupModel;
use Packages\Core\Services\CorePackageStateService;
use Packages\Core\Support\CorePackageStateData;

/**
 * Endpoints de gestion remota del deploy (consumidos por el CMS, firma HMAC
 * en CoreControlAuthMiddleware). No aparecen en la documentacion publica.
 */
class CoreControlController extends Controller
{
    public function __construct(private readonly CorePackageStateService $stateService)
    {
    }

    public function showState(): JsonResponse
    {
        return response()->json([
            'state' => $this->statePayload($this->stateService->current()),
            'package' => config('core.package'),
            'quotas' => CoreQuotaMetricModel::query()->orderBy('metric_key')->get()->map(fn ($m) => [
                'metric' => $m->metric_key,
                'limit' => $m->limit_value,
                'current' => $m->current_value,
                'measured_at' => $m->measured_at?->toIso8601String(),
                'on_exceed' => $m->on_exceed,
                'exceeded_at' => $m->exceeded_at?->toIso8601String(),
            ]),
        ]);
    }

    public function updateState(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:active,grace,suspended',
            'reason' => 'nullable|string|max:100',
            'message' => 'nullable|string|max:1000',
            'grace_until' => 'nullable|date',
        ]);

        $state = $this->stateService->set(
            CorePackageStatusEnum::from($data['status']),
            $data['reason'] ?? null,
            $data['message'] ?? null,
            isset($data['grace_until']) ? CarbonImmutable::parse($data['grace_until']) : null,
            'cms',
        );

        return response()->json(['state' => $this->statePayload($state)]);
    }

    public function usage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => 'nullable|string|in:hour,day',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:1000',
        ]);

        $query = CoreUsageRollupModel::query()
            ->where('period', $data['period'] ?? 'day')
            ->orderByDesc('period_start');

        if (isset($data['from'])) {
            $query->where('period_start', '>=', CarbonImmutable::parse($data['from']));
        }

        if (isset($data['to'])) {
            $query->where('period_start', '<=', CarbonImmutable::parse($data['to']));
        }

        return response()->json($query->paginate((int) ($data['per_page'] ?? 200)));
    }

    /**
     * Store a new control secret (delivered by the CMS, signed with the CURRENT secret). Once stored
     * it overrides CORE_CONTROL_SECRET for verification — the old secret stops working immediately.
     */
    public function rotateSecret(Request $request): JsonResponse
    {
        $data = $request->validate([
            'new_secret' => 'required|string|min:16|max:128',
        ]);

        CorePackageStateModel::query()->updateOrCreate(
            ['id' => 1],
            ['control_secret' => $data['new_secret']],
        );

        return response()->json([
            'rotated' => true,
            'suffix' => substr($data['new_secret'], -4),
        ]);
    }

    private function statePayload(CorePackageStateData $state): array
    {
        return [
            'status' => $state->status->value,
            'effective_status' => $state->effectiveStatus()->value,
            'reason' => $state->reason,
            'message' => $state->message,
            'grace_until' => $state->graceUntil?->toIso8601String(),
            'changed_by' => $state->changedBy,
        ];
    }
}
