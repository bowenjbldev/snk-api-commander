<?php

namespace Packages\Commander\Http\Controllers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Packages\Commander\Exceptions\CommanderControlException;
use Packages\Commander\Models\ActivityEventModel;
use Packages\Commander\Models\DeploymentModel;
use Packages\Commander\Services\CommanderControlClient;
use Packages\Commander\Services\CommanderDeploymentSyncService;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Deployments')]
class CommanderDeploymentsController extends CoreBaseApiController
{
    protected string $modelClass = DeploymentModel::class;

    protected string $indexPermission = 'index deployments';
    protected string $showPermission = 'show deployments';
    protected string $storePermission = 'create deployments';
    protected string $updatePermission = 'update deployments';
    protected string $destroyPermission = 'delete deployments';

    protected array $allowedWith = ['client', 'subscription', 'quotas'];

    protected array $validationRulesStore = [
        'id' => 'required|string|max:64|unique:commander_deployments,id',
        'name' => 'required|string|max:255',
        'subdomain' => 'required|string|max:255',
        'client_id' => 'required|string|exists:commander_clients,id',
        'package' => 'required|string|max:255',
        'version' => 'required|string|max:32',
        'base_url' => 'required|string|max:255',
        'status' => 'nullable|in:active,grace,suspended',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'nullable|string|max:255',
        'subdomain' => 'nullable|string|max:255',
        'client_id' => 'nullable|string|exists:commander_clients,id',
        'package' => 'nullable|string|max:255',
        'version' => 'nullable|string|max:32',
        'base_url' => 'nullable|string|max:255',
        'status' => 'nullable|in:active,grace,suspended',
    ];

    /** Operator state actions are guarded by a dedicated control permission, not plain CRUD update. */
    private const CONTROL_PERMISSION = 'control deployments';

    public function __construct(
        private readonly CommanderControlClient $control,
        private readonly CommanderDeploymentSyncService $sync,
    ) {
    }

    /** Push a suspension (402) to the deploy, then mirror the returned state locally. */
    public function suspend(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }

        if (($deployment = $this->findModel($id)) === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $data = $request->validate([
            'reason' => 'required|string|in:payment_overdue,abuse,manual',
            'message' => 'nullable|string|max:280',
        ]);

        try {
            $result = $this->control->setState($deployment, 'suspended', $data['reason'], $data['message'] ?? null, null);
        } catch (CommanderControlException $e) {
            return $this->controlError($e);
        }

        $this->mirrorEchoedState($deployment, $result);
        $this->recordActivity('suspend', $deployment, $data['reason'], $data['message'] ?? null);

        return response()->json($deployment->fresh());
    }

    /** Push a time-boxed grace period to the deploy (grace_until from ISO or grace_hours). */
    public function grace(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }

        if (($deployment = $this->findModel($id)) === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $data = $request->validate([
            'reason' => 'required|string|in:payment_overdue,abuse,manual',
            'message' => 'nullable|string|max:280',
            'grace_until' => 'nullable|date',
            'grace_hours' => 'nullable|integer|min:1|max:720',
        ]);

        $graceUntil = isset($data['grace_until'])
            ? CarbonImmutable::parse($data['grace_until'])
            : CarbonImmutable::now()->addHours((int) ($data['grace_hours'] ?? config('commander.commander.operator.default_grace_hours', 72)));

        try {
            $result = $this->control->setState($deployment, 'grace', $data['reason'], $data['message'] ?? null, $graceUntil);
        } catch (CommanderControlException $e) {
            return $this->controlError($e);
        }

        $this->mirrorEchoedState($deployment, $result);
        $this->recordActivity('grace', $deployment, $data['reason'], $data['message'] ?? null);

        return response()->json($deployment->fresh());
    }

    /** Restore the deploy to active and clear reason/message/grace. */
    public function resume(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }

        if (($deployment = $this->findModel($id)) === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        try {
            $result = $this->control->setState($deployment, 'active', null, null, null);
        } catch (CommanderControlException $e) {
            return $this->controlError($e);
        }

        $this->mirrorEchoedState($deployment, $result);
        $this->recordActivity('resume', $deployment, null, null);

        return response()->json($deployment->fresh());
    }

    /** Pull live state + quotas + last-24h usage from the deploy into the local mirror. */
    public function sync(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }

        if (($deployment = $this->findModel($id)) === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        try {
            $fresh = $this->sync->sync($deployment);
        } catch (CommanderControlException $e) {
            return $this->controlError($e);
        }

        return response()->json($fresh->load('quotas'));
    }

    /** Proxy the deploy's real usage rollups (core_usage_rollups) for the Usage-tab charts. */
    public function usage(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }

        if (($deployment = $this->findModel($id)) === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $data = $request->validate([
            'period' => 'nullable|in:hour,day',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:1000',
        ]);

        $query = array_filter([
            'period' => $data['period'] ?? 'hour',
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'per_page' => $data['per_page'] ?? 1000,
        ], static fn ($v) => $v !== null);

        try {
            $usage = $this->control->getUsage($deployment, $query);
        } catch (CommanderControlException $e) {
            return $this->controlError($e);
        }

        return response()->json($usage);
    }

    /** Proxy the deploy's real request logs (core_request_logs) for the Logs view. */
    public function logs(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }

        if (($deployment = $this->findModel($id)) === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
            'since' => 'nullable|date',
            'status_class' => 'nullable|integer|in:2,3,4,5',
        ]);

        $query = array_filter([
            'limit' => $data['limit'] ?? 100,
            'since' => $data['since'] ?? null,
            'status_class' => $data['status_class'] ?? null,
        ], static fn ($v) => $v !== null);

        try {
            $logs = $this->control->getLogs($deployment, $query);
        } catch (CommanderControlException $e) {
            return $this->controlError($e);
        }

        // Tag each row with the deployment id so the SPA receives complete LogEntry objects.
        $rows = array_map(
            static fn ($row) => array_merge((array) $row, ['deployment_id' => $deployment->id]),
            (array) ($logs['data'] ?? []),
        );

        return response()->json(['data' => $rows]);
    }

    /** Generate a new control secret, deliver it to the deploy (signed with the old one), then adopt it. */
    public function rotateSecret(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }

        if (($deployment = $this->findModel($id)) === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $new = 'sk_ctl_live_' . bin2hex(random_bytes(12));

        try {
            // send() signs with the row's CURRENT secret (read before we change it below).
            $this->control->rotateSecret($deployment, $new);
        } catch (CommanderControlException $e) {
            return $this->controlError($e);
        }

        // Remote accepted → adopt locally. Inconsistency window (wider than just this save): if the
        // deploy committed $new but its 2xx was lost (timeout), send() throws → 502 above and we never
        // adopt $new, leaving the Commander on the old secret while the deploy is on $new. A failing
        // save() here has the same end state. Recovery in both cases is to re-provision the deployment.
        $deployment->control_secret = $new;
        $deployment->control_secret_rotated_at = CarbonImmutable::now();
        $deployment->save();

        $this->recordActivity('rotate', $deployment, null, '…' . substr($new, -4));

        return response()->json($deployment->fresh());
    }

    /**
     * Mirror the deploy's echoed state after a successful control call. A 2xx without a populated state
     * envelope (shouldn't happen with Core, but the contract tolerates it) would otherwise leave a stale
     * mirror behind a 200; fall through to a full sync so the response reflects live state.
     */
    private function mirrorEchoedState(DeploymentModel $deployment, array $result): void
    {
        if (($result['state'] ?? []) !== []) {
            $this->sync->applyState($deployment, $result['state']);

            return;
        }

        try {
            $this->sync->sync($deployment);
        } catch (CommanderControlException) {
            // best-effort refresh; the remote action already succeeded, leave the mirror as-is
        }
    }

    private function recordActivity(string $kind, DeploymentModel $deployment, ?string $reason, ?string $message): void
    {
        ActivityEventModel::create([
            'id' => 'act_' . Str::random(24),
            'at' => CarbonImmutable::now(),
            'kind' => $kind,
            'deployment_id' => $deployment->id,
            'invoice_id' => null,
            'reason' => $reason,
            'by' => auth()->user()?->name ?? 'operator',
            'message' => $message,
        ]);
    }

    private function controlError(CommanderControlException $e): JsonResponse
    {
        return response()->json([
            'error' => 'Control plane call failed.',
            'detail' => $e->getMessage(),
            'remote_status' => $e->remoteStatus,
        ], 502);
    }
}
