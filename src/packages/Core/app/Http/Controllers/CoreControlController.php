<?php

namespace Packages\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Core\Models\CorePackageStateModel;
use Packages\Core\Models\CoreQuotaMetricModel;
use Packages\Core\Models\CoreUsageRollupModel;
use Packages\Core\Services\CorePackageStateService;
use Packages\Core\Support\CorePackageStateData;

/**
 * Endpoints de gestion remota del deploy (consumidos por el Commander, firma HMAC
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

    /** Recent raw request logs (core_request_logs) for the Commander's Logs view. */
    public function logs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
            'since' => 'nullable|date',
            'status_class' => 'nullable|integer|in:2,3,4,5',
        ]);

        $query = DB::table('core_request_logs')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if (isset($data['since'])) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($data['since']));
        }

        if (isset($data['status_class'])) {
            $base = (int) $data['status_class'] * 100;
            $query->whereBetween('status', [$base, $base + 99]);
        }

        $rows = $query->limit((int) ($data['limit'] ?? 100))->get()->map(fn ($r) => [
            'id' => (string) $r->id,
            'occurred_at' => CarbonImmutable::parse($r->occurred_at)->toIso8601String(),
            'method' => $r->method,
            'route' => $r->route ?? $r->path ?? '',
            'status' => (int) $r->status,
            'duration_ms' => (int) $r->duration_ms,
            'user_id' => $r->user_id !== null ? (string) $r->user_id : null,
            'token_id' => $r->token_id !== null ? (string) $r->token_id : '',
            'ip' => (string) ($r->ip ?? ''),
            'request_bytes' => (int) ($r->request_bytes ?? 0),
            'response_bytes' => (int) ($r->response_bytes ?? 0),
        ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * Store a new control secret (delivered by the Commander, signed with the CURRENT secret). Once stored
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
