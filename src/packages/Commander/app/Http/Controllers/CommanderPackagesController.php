<?php

namespace Packages\Commander\Http\Controllers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Commander\Jobs\CommanderDeprovisionPackageJob;
use Packages\Commander\Models\CommanderActivityEventModel;
use Packages\Commander\Models\CommanderPackageActivityDailyModel;
use Packages\Commander\Models\CommanderPackageCommitModel;
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
 * API sobre commander_packages: filas de catálogo y una fila operativa por instancia.
 * index() sincroniza productos desplegados y enriquece las filas con disco/planes. Las acciones
 * operativas exigen `provisioned_at` y siempre se direccionan por la PK numérica de la instancia.
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

    private const QUOTA_UNITS = [
        'requests_day' => 'count',
        'disk_db_mb' => 'mb',
        'disk_storage_mb' => 'mb',
        'users_total' => 'count',
    ];

    public function __construct(
        private readonly CommanderPackageRegistryService $registry,
        private readonly CorePackageStateService $state,
    ) {}

    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $this->registry->syncCatalog();
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

        $record = CommanderPackageModel::query()
            ->with(['client', 'subscription', 'quotas'])
            ->find($package);

        if ($record === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $planCount = CommanderPlanModel::query()->where('package', $record->name)->count();

        return response()->json(array_merge($record->toArray(), [
            'on_disk' => in_array($record->name, $this->registry->availableOnDisk(), true),
            'plan_count' => $planCount,
        ]));
    }

    /** La ruta `packages/{package}` se direcciona por la PK numerica. */
    protected function findModel($id, bool $applyWith = false): ?Model
    {
        $query = CommanderPackageModel::query();

        if ($applyWith) {
            $query = $this->applyWith($query);
        }

        return $query->find($id);
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

            foreach ($plan->quotaLimits() as $metric => $limit) {
                $quota = CommanderQuotaModel::firstOrNew([
                    'package_id' => $packageRow->id,
                    'metric_key' => $metric,
                ]);
                if (! $quota->exists) {
                    $quota->current_value = 0;
                    $quota->unit = self::QUOTA_UNITS[$metric] ?? 'count';
                    $quota->on_exceed = $limit === null ? 'none' : 'suspend';
                } elseif ($limit === null) {
                    $quota->on_exceed = 'none';
                }
                $quota->limit_value = $limit;
                $quota->save();
            }

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
            ->when(isset($data['since']), fn ($q) => $q->where('created_at', '>=', $data['since']))
            ->when(isset($data['status_class']), function ($q) use ($data) {
                $base = ((int) $data['status_class']) * 100;

                return $q->where('status', '>=', $base)->where('status', '<', $base + 100);
            })
            ->orderByDesc('created_at')
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
     * Git insights del package: estado del repo (cursor de sync), resumen agregado de TODO el
     * historial, rollup diario de actividad (heatmap + code frequency) acotado a la ventana, y los
     * commits recientes. Alimentado por `commander:git:sync` (tablas commander_package_*).
     */
    /**
     * Platform-wide recent commits across ALL packages (newest first), each enriched with its
     * package name/label. Powers the Dashboard "Recent commits" card. Cross-package aggregation, so
     * it is NOT package-scoped (unlike insights()).
     */
    public function commits(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $commits = CommanderPackageCommitModel::query()
            ->with('package:id,name,label')
            ->orderByDesc('committed_at')
            ->limit($data['limit'] ?? 15)
            ->get(['id', 'package_id', 'sha', 'committed_at', 'author_name', 'author_email', 'message', 'additions', 'deletions', 'files_changed']);

        return response()->json([
            'data' => $commits->map(fn ($c) => [
                'sha' => $c->sha,
                'committed_at' => $c->committed_at?->toJSON(),
                'author_name' => $c->author_name,
                'author_email' => $c->author_email,
                'message' => $c->message,
                'additions' => (int) $c->additions,
                'deletions' => (int) $c->deletions,
                'files_changed' => (int) $c->files_changed,
                'package_id' => $c->package_id,
                'package' => $c->package?->name,
                'package_label' => $c->package?->label,
            ])->all(),
        ]);
    }

    /**
     * Platform-wide git code frequency: daily additions/deletions summed across all packages over
     * a configurable window (12 weeks by default).
     */
    public function codeFrequency(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $data = $request->validate([
            'days' => 'nullable|integer|min:1|max:730',
        ]);

        $since = CarbonImmutable::now()->subDays($data['days'] ?? 84)->startOfDay();

        $daily = CommanderPackageActivityDailyModel::query()
            ->where('day', '>=', $since->toDateString())
            ->selectRaw('day')
            ->selectRaw('SUM(commit_count) as commit_count')
            ->selectRaw('SUM(additions) as additions')
            ->selectRaw('SUM(deletions) as deletions')
            ->selectRaw('SUM(files_changed) as files_changed')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return response()->json([
            'data' => $daily->map(fn ($d) => [
                'day' => $d->day->toDateString(),
                'commit_count' => (int) $d->commit_count,
                'additions' => (int) $d->additions,
                'deletions' => (int) $d->deletions,
                'files_changed' => (int) $d->files_changed,
            ])->all(),
        ]);
    }

    public function insights(Request $request, $package = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }

        if (($packageRow = $this->findPackageRow($package)) === null) {
            return response()->json(['error' => 'Package not provisioned'], 404);
        }

        $data = $request->validate([
            'days' => 'nullable|integer|min:1|max:730',
        ]);

        $days = $data['days'] ?? 182; // ~26 semanas: calendario de contribuciones legible
        $since = CarbonImmutable::now()->subDays($days - 1)->startOfDay();

        $repo = $packageRow->repo;

        $daily = CommanderPackageActivityDailyModel::query()
            ->where('package_id', $packageRow->id)
            ->where('day', '>=', $since->toDateString())
            ->orderBy('day')
            ->get(['day', 'commit_count', 'additions', 'deletions', 'files_changed']);

        $recent = CommanderPackageCommitModel::query()
            ->where('package_id', $packageRow->id)
            ->orderByDesc('committed_at')
            ->limit(15)
            ->get(['sha', 'committed_at', 'author_name', 'author_email', 'message', 'additions', 'deletions', 'files_changed']);

        // Resumen sobre TODO el historial (no solo la ventana del calendario).
        $agg = CommanderPackageCommitModel::query()
            ->where('package_id', $packageRow->id)
            ->selectRaw('COUNT(*) as total_commits')
            ->selectRaw('COALESCE(SUM(additions), 0) as total_additions')
            ->selectRaw('COALESCE(SUM(deletions), 0) as total_deletions')
            ->selectRaw('COUNT(DISTINCT author_email) as total_authors')
            ->selectRaw('MIN(committed_at) as first_commit_at')
            ->selectRaw('MAX(committed_at) as last_commit_at')
            ->first();

        $iso = fn ($v) => $v ? CarbonImmutable::parse($v)->toJSON() : null;

        return response()->json([
            'data' => [
                'repo' => $repo ? [
                    'provider' => $repo->provider,
                    'workspace' => $repo->workspace,
                    'repo_slug' => $repo->repo_slug,
                    'path_prefix' => $repo->path_prefix,
                    'default_branch' => $repo->default_branch,
                    'last_synced_at' => $repo->last_synced_at?->toJSON(),
                    'last_synced_sha' => $repo->last_synced_sha,
                ] : null,
                'summary' => [
                    'total_commits' => (int) ($agg->total_commits ?? 0),
                    'total_additions' => (int) ($agg->total_additions ?? 0),
                    'total_deletions' => (int) ($agg->total_deletions ?? 0),
                    'total_authors' => (int) ($agg->total_authors ?? 0),
                    'active_days' => $daily->where('commit_count', '>', 0)->count(),
                    'first_commit_at' => $iso($agg->first_commit_at ?? null),
                    'last_commit_at' => $iso($agg->last_commit_at ?? null),
                ],
                'daily' => $daily->map(fn ($d) => [
                    'day' => $d->day->toDateString(),
                    'commit_count' => (int) $d->commit_count,
                    'additions' => (int) $d->additions,
                    'deletions' => (int) $d->deletions,
                    'files_changed' => (int) $d->files_changed,
                ])->all(),
                'recent_commits' => $recent->map(fn ($c) => [
                    'sha' => $c->sha,
                    'committed_at' => $c->committed_at?->toJSON(),
                    'author_name' => $c->author_name,
                    'author_email' => $c->author_email,
                    'message' => $c->message,
                    'additions' => (int) $c->additions,
                    'deletions' => (int) $c->deletions,
                    'files_changed' => (int) $c->files_changed,
                ])->all(),
            ],
        ]);
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

        // Corta el trafico antes de delegar el DDL al worker privilegiado.
        $packageRow->forceFill(['provisioned_at' => null])->save();
        CommanderDeprovisionPackageJob::dispatch($packageRow->id);

        return response()->json(['message' => 'Package de-provisioning queued.'], 202);
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
            ->find($package);
    }

    private function recordActivity(string $kind, CommanderPackageModel $packageRow, ?string $reason, ?string $message): void
    {
        CommanderActivityEventModel::create([
            'kind' => $kind,
            'package_id' => $packageRow->id,
            'invoice_id' => null,
            'reason' => $reason,
            'message' => $message,
        ]);
    }
}
