<?php

namespace Packages\Commander\Http\Controllers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Commander\Models\CommanderActivityEventModel;
use Packages\Commander\Models\CommanderDeploymentModel;
use Packages\Commander\Models\CommanderQuotaMetricModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Commander\Models\CommanderUsageRollupModel;
use Packages\Commander\Models\CommanderUsageSnapshotModel;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Services\CorePackageStateService;

/**
 * In-process (misma app/BD): las acciones de estado escriben directamente la fila commander_packages
 * via CorePackageStateService; uso y logs se leen de commander_usage_rollups /
 * commander_request_logs filtrando por package_id.
 */
#[Group('Deployments')]
class CommanderDeploymentsController extends CoreBaseApiController
{
    protected string $modelClass = CommanderDeploymentModel::class;

    protected string $indexPermission = 'index deployments';
    protected string $showPermission = 'show deployments';
    protected string $storePermission = 'create deployments';
    protected string $updatePermission = 'update deployments';
    protected string $destroyPermission = 'delete deployments';

    protected array $allowedWith = ['client', 'subscription', 'quotas', 'corePackage'];

    protected array $validationRulesStore = [
        'id' => 'required|string|max:64|unique:commander_deployments,id',
        'name' => 'required|string|max:255',
        'subdomain' => 'required|string|max:255',
        'client_id' => 'required|string|exists:commander_clients,id',
        'package' => 'required|string|max:255',
        'version' => 'required|string|max:32',
        'base_url' => 'required|string|max:255',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'nullable|string|max:255',
        'subdomain' => 'nullable|string|max:255',
        'client_id' => 'nullable|string|exists:commander_clients,id',
        'package' => 'nullable|string|max:255',
        'version' => 'nullable|string|max:32',
        'base_url' => 'nullable|string|max:255',
    ];

    /** Operator state actions are guarded by a dedicated control permission, not plain CRUD update. */
    private const CONTROL_PERMISSION = 'control deployments';

    public function __construct(private readonly CorePackageStateService $state)
    {
    }

    /** Suspend (402) the package: write commander_packages directly. */
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

        $this->state->set(
            CorePackageStatusEnum::Suspended,
            $data['reason'],
            $data['message'] ?? null,
            null,
            'operator',
            $deployment->package_id,
        );

        $this->recordActivity('suspend', $deployment, $data['reason'], $data['message'] ?? null);

        return response()->json($deployment->fresh()->load('corePackage'));
    }

    /** Grace period (time-boxed) for the package. */
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
            : CarbonImmutable::now()->addHours((int) ($data['grace_hours'] ?? config('core.quotas.grace_hours', 72)));

        $this->state->set(
            CorePackageStatusEnum::Grace,
            $data['reason'],
            $data['message'] ?? null,
            $graceUntil,
            'operator',
            $deployment->package_id,
        );

        $this->recordActivity('grace', $deployment, $data['reason'], $data['message'] ?? null);

        return response()->json($deployment->fresh()->load('corePackage'));
    }

    /** Restore the package to active. */
    public function resume(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }

        if (($deployment = $this->findModel($id)) === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $this->state->set(
            CorePackageStatusEnum::Active,
            null,
            null,
            null,
            'operator',
            $deployment->package_id,
        );

        $this->recordActivity('resume', $deployment, null, null);

        return response()->json($deployment->fresh()->load('corePackage'));
    }

    /** Real usage rollups (commander_usage_rollups) for the package, for the Usage-tab charts. */
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

        $rollups = CommanderUsageRollupModel::query()
            ->where('package_id', $deployment->package_id)
            ->where('period', $data['period'] ?? 'hour')
            ->when(isset($data['from']), fn ($q) => $q->where('period_start', '>=', $data['from']))
            ->when(isset($data['to']), fn ($q) => $q->where('period_start', '<=', $data['to']))
            ->orderBy('period_start')
            ->paginate($data['per_page'] ?? 1000);

        return response()->json($rollups);
    }

    /** Real request logs (commander_request_logs) for the package, for the Logs view. */
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

        $rows = CommanderRequestLogModel::query()
            ->where('package_id', $deployment->package_id)
            ->when(isset($data['since']), fn ($q) => $q->where('occurred_at', '>=', $data['since']))
            ->when(isset($data['status_class']), function ($q) use ($data) {
                $base = ((int) $data['status_class']) * 100;

                return $q->where('status', '>=', $base)->where('status', '<', $base + 100);
            })
            ->orderByDesc('occurred_at')
            ->limit($data['limit'] ?? 100)
            ->get()
            ->map(fn ($row) => array_merge($row->toArray(), ['deployment_id' => $deployment->id]))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** De-provision: borra la BD del package (DROP DATABASE) y todo su rastro en la commander. */
    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        if (($deployment = $this->findModel($id)) === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $package = $deployment->corePackage;
        $database = $package?->database;

        DB::connection('commander')->transaction(function () use ($deployment, $package) {
            CommanderQuotaModel::where('deployment_id', $deployment->id)->delete();
            CommanderUsageSnapshotModel::where('deployment_id', $deployment->id)->delete();
            CommanderSubscriptionModel::where('deployment_id', $deployment->id)->delete();

            if ($package !== null) {
                CommanderQuotaMetricModel::where('package_id', $package->id)->delete();
                CommanderUsageRollupModel::where('package_id', $package->id)->delete();
                CommanderRequestLogModel::query()->where('package_id', $package->id)->delete();
            }

            $deployment->delete();
            $package?->delete();
        });

        // DROP DATABASE fuera de la transaccion (DDL). sqlite no aplica.
        if (! empty($database) && DB::connection('commander')->getDriverName() !== 'sqlite') {
            DB::connection('commander')->statement("DROP DATABASE IF EXISTS `{$database}`");
        }

        return response()->json(['message' => 'Deployment de-provisioned.']);
    }

    private function recordActivity(string $kind, CommanderDeploymentModel $deployment, ?string $reason, ?string $message): void
    {
        CommanderActivityEventModel::create([
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
}
