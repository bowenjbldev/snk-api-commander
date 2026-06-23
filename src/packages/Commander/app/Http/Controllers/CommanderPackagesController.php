<?php

namespace Packages\Commander\Http\Controllers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Commander\Models\CommanderActivityEventModel;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Commander\Models\CommanderUsageRollupModel;
use Packages\Commander\Services\CommanderPackageRegistryService;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Services\CorePackageStateService;

/**
 * Hub del panel package-centrico sobre la tabla UNICA commander_packages: una fila por package en
 * disco. index() sincroniza desde disco y enriquece cada fila con on_disk + conteo de planes; la fila
 * lleva catalogo (label/description/listing_status) y —si esta provisionada— lo operativo (subdomain,
 * cliente, estado runtime, KPIs) + relaciones. Las acciones operativas (estado, cambio de plan,
 * usage, logs, de-provision) exigen una fila provisionada y se direccionan por `name`.
 */
#[Group('Packages')]
class CommanderPackagesController extends CoreBaseApiController
{
    protected string $modelClass = CommanderPackageModel::class;

    protected string $indexPermission = 'index packages';
    protected string $showPermission = 'show packages';
    protected string $storePermission = 'create packages';
    protected string $updatePermission = 'update packages';
    protected string $destroyPermission = 'delete packages';

    protected array $validationRulesUpdate = [
        'label' => 'nullable|string|max:255',
        'description' => 'nullable|string',
        'listing_status' => 'nullable|in:active,inactive',
    ];

    private const CONTROL_PERMISSION = 'control packages';

    public function __construct(
        private readonly CommanderPackageRegistryService $registry,
        private readonly CorePackageStateService $state,
    )
    {
    }

    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $this->registry->syncFromDisk();
        $onDisk = $this->registry->availableOnDisk();
        $planCounts = CommanderPlanModel::query()
            ->selectRaw('package, COUNT(*) as c')->groupBy('package')->pluck('c', 'package');

        $rows = CommanderPackageModel::query()
            ->with(['client', 'subscription', 'quotas'])
            ->orderBy('name')
            ->get()
            ->map(fn (CommanderPackageModel $p) => array_merge($p->toArray(), [
                'on_disk' => in_array($p->name, $onDisk, true),
                'plan_count' => (int) ($planCounts[$p->name] ?? 0),
            ]));

        return response()->json($rows);
    }

    public function show($package = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }

        $this->registry->syncFromDisk();

        $record = CommanderPackageModel::query()
            ->with(['client', 'subscription', 'quotas'])
            ->where('name', $package)
            ->first();

        if ($record === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $planCount = CommanderPlanModel::query()->where('package', $record->name)->count();

        return response()->json(array_merge($record->toArray(), [
            'on_disk' => in_array($record->name, $this->registry->availableOnDisk(), true),
            'plan_count' => $planCount,
        ]));
    }

    public function update(Request $request, $package = null): JsonResponse
    {
        return parent::update($request, $package);
    }

    /**
     * La PK es numerica (id), pero la ruta `packages/{package}` direcciona por `name` (StudlyCase) —
     * la clave de negocio compartida con commander_plans.
     */
    protected function findModel($id, bool $applyWith = false): ?Model
    {
        $query = CommanderPackageModel::query();

        if ($applyWith) {
            $query = $this->applyWith($query);
        }

        return $query->where('name', $id)->first();
    }

    /** Suspend (402) the provisioned package: write its commander_packages row directly. */
    public function suspend(Request $request, $package = null): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }

        if (($packageRow = $this->findPackageRow($package)) === null) {
            return response()->json(['error' => 'Package not provisioned'], 404);
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
            $packageRow->id,
        );

        $this->recordActivity('suspend', $packageRow, $data['reason'], $data['message'] ?? null);

        return response()->json($packageRow->fresh()->load(['client', 'subscription', 'quotas']));
    }

    /** Grace period (time-boxed) for the provisioned package. */
    public function grace(Request $request, $package = null): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }

        if (($packageRow = $this->findPackageRow($package)) === null) {
            return response()->json(['error' => 'Package not provisioned'], 404);
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
            $packageRow->id,
        );

        $this->recordActivity('grace', $packageRow, $data['reason'], $data['message'] ?? null);

        return response()->json($packageRow->fresh()->load(['client', 'subscription', 'quotas']));
    }

    /** Restore the provisioned package to active. */
    public function resume(Request $request, $package = null): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }

        if (($packageRow = $this->findPackageRow($package)) === null) {
            return response()->json(['error' => 'Package not provisioned'], 404);
        }

        $this->state->set(
            CorePackageStatusEnum::Active,
            null,
            null,
            null,
            'operator',
            $packageRow->id,
        );

        $this->recordActivity('resume', $packageRow, null, null);

        return response()->json($packageRow->fresh()->load(['client', 'subscription', 'quotas']));
    }

    /**
     * Cambiar de plan: cierra el término de subscripción vigente y abre uno nuevo (historial 1:N).
     * El nuevo término snapshotea el plan elegido y arranca en 'pending'; hereda auto_suspend.
     */
    public function changePlan(Request $request, $package = null): JsonResponse
    {
        if ($response = $this->requirePermission(self::CONTROL_PERMISSION)) {
            return $response;
        }

        if (($packageRow = $this->findPackageRow($package)) === null) {
            return response()->json(['error' => 'Package not provisioned'], 404);
        }

        $data = $request->validate([
            'plan_id' => 'required|integer|exists:commander_plans,id',
        ]);

        $plan = CommanderPlanModel::findOrFail($data['plan_id']);

        if (Str::studly($plan->package) !== $packageRow->name) {
            return response()->json(['error' => 'Plan does not belong to this package.'], 422);
        }
        if ($plan->status !== 'active') {
            return response()->json(['error' => 'Plan is not active.'], 422);
        }

        $now = CarbonImmutable::now();

        $new = DB::connection('commander')->transaction(function () use ($packageRow, $plan, $now) {
            // Bloquea el término vigente para evitar abrir dos en cambios concurrentes.
            $current = CommanderSubscriptionModel::query()
                ->where('package_id', $packageRow->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if ($current && (int) $current->plan_id === (int) $plan->id) {
                return null; // ya está en este plan: no-op
            }

            if ($current) {
                $current->ended_at = $now;
                $current->save();
            }

            $created = CommanderSubscriptionModel::create([
                'client_id' => $packageRow->client_id,
                'package_id' => $packageRow->id,
                'plan_id' => $plan->id,
                'plan' => $plan->name,
                'price_monthly' => $plan->price_monthly,
                'billing_status' => 'pending',
                'auto_suspend' => $current?->auto_suspend ?? false,
                'started_at' => $now,
                'ended_at' => null,
            ]);

            $packageRow->subscription_id = $created->id;
            $packageRow->save();

            return $created;
        });

        if ($new === null) {
            return response()->json(['error' => 'Package is already on this plan.'], 422);
        }

        $this->recordActivity('change_plan', $packageRow, "plan:{$plan->name}", null);

        return response()->json($packageRow->fresh()->load(['client', 'subscription', 'quotas']));
    }

    /** Real usage rollups (commander_usage_rollups) for the package. */
    public function usage(Request $request, $package = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }

        if (($packageRow = $this->findPackageRow($package)) === null) {
            return response()->json(['error' => 'Package not provisioned'], 404);
        }

        $data = $request->validate([
            'period' => 'nullable|in:hour,day',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:1000',
        ]);

        $rollups = CommanderUsageRollupModel::query()
            ->where('package_id', $packageRow->id)
            ->where('period', $data['period'] ?? 'hour')
            ->when(isset($data['from']), fn ($q) => $q->where('period_start', '>=', $data['from']))
            ->when(isset($data['to']), fn ($q) => $q->where('period_start', '<=', $data['to']))
            ->orderBy('period_start')
            ->paginate($data['per_page'] ?? 1000);

        return response()->json($rollups);
    }

    /** Real request logs (commander_request_logs) for the package. */
    public function logs(Request $request, $package = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }

        if (($packageRow = $this->findPackageRow($package)) === null) {
            return response()->json(['error' => 'Package not provisioned'], 404);
        }

        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
            'since' => 'nullable|date',
            'status_class' => 'nullable|integer|in:2,3,4,5',
        ]);

        $rows = CommanderRequestLogModel::query()
            ->where('package_id', $packageRow->id)
            ->when(isset($data['since']), fn ($q) => $q->where('occurred_at', '>=', $data['since']))
            ->when(isset($data['status_class']), function ($q) use ($data) {
                $base = ((int) $data['status_class']) * 100;

                return $q->where('status', '>=', $base)->where('status', '<', $base + 100);
            })
            ->orderByDesc('occurred_at')
            ->limit($data['limit'] ?? 100)
            ->get()
            ->map(fn ($row) => array_merge($row->toArray(), [
                'package' => $packageRow->name,
                'subdomain' => $packageRow->client?->slug, // el subdominio ES el del cliente
            ]))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * De-provision (soft): borra la BD del package y los datos OPERATIVOS de la instancia
     * (metering, cuotas), y anula las columnas operativas de la fila. La fila (catalogo: name/label/
     * description/listing_status) y el historial de billing (subscriptions/invoices) se conservan.
     */
    public function destroy($package = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        if (($packageRow = $this->findPackageRow($package)) === null) {
            return response()->json(['error' => 'Package not provisioned'], 404);
        }

        $database = $packageRow->database;
        $packageId = $packageRow->id;

        DB::connection('commander')->transaction(function () use ($packageRow, $packageId) {
            // Datos operativos/metering de la instancia muerta (no son historial de billing).
            CommanderRequestLogModel::query()->where('package_id', $packageId)->delete();
            CommanderQuotaModel::query()->where('package_id', $packageId)->delete();
            CommanderUsageRollupModel::query()->where('package_id', $packageId)->delete();

            // Cierra el término de subscripción vigente; el historial (términos cerrados) se conserva.
            CommanderSubscriptionModel::query()
                ->where('package_id', $packageId)
                ->whereNull('ended_at')
                ->update(['ended_at' => CarbonImmutable::now()]);

            // Soft: anula lo operativo (incluida la identidad client_id/slug, que libera el par), conserva
            // el catalogo (name/label) + el historial de billing (subscriptions/invoices via package_id).
            $packageRow->forceFill([
                'slug' => null,
                'database' => null,
                'client_id' => null,
                'subscription_id' => null,
                'status' => 'active',
                'reason' => null,
                'message' => null,
                'grace_until' => null,
                'changed_by' => 'operator',
                'changed_at' => CarbonImmutable::now(),
                'requests_24h' => 0,
                'quota_health' => 0,
                'last_seen' => null,
                'provisioned_at' => null,
            ])->save();
        });

        // DROP DATABASE fuera de la transaccion (DDL). sqlite no aplica.
        if (! empty($database) && DB::connection('commander')->getDriverName() !== 'sqlite') {
            DB::connection('commander')->statement("DROP DATABASE IF EXISTS `{$database}`");
        }

        return response()->json(['message' => 'Package de-provisioned.']);
    }

    /** La fila del package SOLO si esta provisionada (tiene instancia viva). */
    private function findPackageRow($package): ?CommanderPackageModel
    {
        if ($package === null) {
            return null;
        }

        return CommanderPackageModel::query()
            ->provisioned()
            ->with(['client', 'subscription', 'quotas'])
            ->where('name', $package)
            ->first();
    }

    private function recordActivity(string $kind, CommanderPackageModel $packageRow, ?string $reason, ?string $message): void
    {
        CommanderActivityEventModel::create([
            'at' => CarbonImmutable::now(),
            'kind' => $kind,
            'package_id' => $packageRow->id,
            'invoice_id' => null,
            'reason' => $reason,
            'by' => 'operator',
            'message' => $message,
        ]);
    }
}
