<?php

namespace Packages\Commander\Http\Controllers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Commander\Models\ClientModel;
use Packages\Commander\Models\DeploymentModel;
use Packages\Commander\Models\PlanModel;
use Packages\Commander\Models\QuotaModel;
use Packages\Commander\Models\SubscriptionModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Models\CorePackageModel;
use Packages\Core\Models\CoreQuotaMetricModel;

/**
 * Client-first provisioning, EN UN PASO e in-process: registra CLIENTE (nuevo o existente) + PLAN,
 * crea la instancia (fila core_packages = subdominio activo), su suscripcion y cuotas, migra las
 * tablas del package sobre la BD compartida y siembra su admin. No hay host_command ni script: el
 * codigo del package ya viaja por git; aqui solo se da de alta la instancia.
 */
#[Group('Provisioning')]
class CommanderProvisioningController extends CoreBaseApiController
{
    private const PROVISION_PERMISSION = 'provision deployments';

    /** metric_key → unit, for the quota rows mirrored from a plan. */
    private const QUOTA_UNITS = [
        'requests_day' => 'count',
        'disk_db_mb' => 'mb',
        'disk_storage_mb' => 'mb',
        'users_total' => 'count',
    ];

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission(self::PROVISION_PERMISSION)) {
            return $response;
        }

        $data = $request->validate([
            'plan_id' => 'required|string|exists:commander_plans,id',
            'subdomain' => 'nullable|string|max:255|regex:/^[a-z][a-z0-9-]{1,30}$/',
            // Existing client …
            'client_id' => 'nullable|string|exists:commander_clients,id',
            // … or a new client object (required unless client_id given)
            'client' => 'nullable|array',
            'client.id' => 'required_without:client_id|string|max:64',
            'client.name' => 'required_without:client_id|string|max:255',
            'client.email' => 'required_without:client_id|email|max:255',
            'client.company' => 'nullable|string|max:255',
            'client.phone' => 'nullable|string|max:50',
            'client.billing_email' => 'nullable|email|max:255',
            'client.tax_id' => 'nullable|string|max:64',
            'client.address_line1' => 'nullable|string|max:255',
            'client.address_line2' => 'nullable|string|max:255',
            'client.city' => 'nullable|string|max:128',
            'client.postal_code' => 'nullable|string|max:32',
            'client.country' => 'nullable|string|size:2',
            'client.payment_terms' => 'nullable|in:prepaid,net_15,net_30,net_60',
        ]);

        if (empty($data['client_id']) && empty($data['client'])) {
            return response()->json(['error' => 'Provide client_id or a client object.'], 422);
        }

        $plan = PlanModel::findOrFail($data['plan_id']);
        $package = Str::studly($plan->package);
        $subdomain = $data['subdomain'] ?? Str::of($package)->snake()->replace('_', '-')->toString();
        $deploymentId = $subdomain;
        $database = Str::snake($package); // BD propia del package (multi-tenant), ej. Shop -> shop

        // El codigo del package debe existir en disco (viaja por git, no se genera aqui).
        if (! is_dir(base_path("packages/{$package}"))) {
            return response()->json(['error' => "Package code '{$package}' is not deployed."], 422);
        }

        if (DeploymentModel::whereKey($deploymentId)->exists() || CorePackageModel::where('subdomain', $subdomain)->exists()) {
            return response()->json(['error' => "A deployment '{$deploymentId}' already exists."], 409);
        }

        if (! empty($data['client']) && ClientModel::whereKey($data['client']['id'])->exists()) {
            return response()->json(['error' => "Client '{$data['client']['id']}' already exists."], 422);
        }

        $baseDomain = (string) config('core.base_domain', 'snake.test');
        $fqdn = "{$subdomain}.{$baseDomain}";

        [$deployment, $corePackage] = DB::connection('central')->transaction(function () use ($data, $plan, $package, $subdomain, $deploymentId, $fqdn, $database) {
            $now = CarbonImmutable::now();

            $clientId = $data['client_id'] ?? null;
            if ($clientId === null) {
                $c = $data['client'];
                ClientModel::create(array_merge(
                    ['id' => $c['id'], 'name' => $c['name'], 'email' => $c['email'], 'status' => 'active'],
                    array_intersect_key($c, array_flip([
                        'company', 'phone', 'billing_email', 'tax_id', 'address_line1',
                        'address_line2', 'city', 'postal_code', 'country', 'payment_terms',
                    ])),
                ));
                $clientId = $c['id'];
            }

            // Instancia: la fila core_packages enciende el subdominio (la resuelve el middleware por host).
            $corePackage = CorePackageModel::create([
                'name' => $package,
                'subdomain' => $subdomain,
                'database' => $database,
                'status' => 'active',
                'changed_by' => 'operator',
                'changed_at' => $now,
            ]);

            $subscriptionId = 'sub_' . Str::lower(Str::random(20));

            $deployment = DeploymentModel::create([
                'id' => $deploymentId,
                'name' => $deploymentId,
                'subdomain' => $fqdn,
                'client_id' => $clientId,
                'package_id' => $corePackage->id,
                'package' => $package,
                'version' => '1.0.0',
                'base_url' => "https://{$fqdn}/api/v1",
                'subscription_id' => $subscriptionId,
                'requests_24h' => 0,
                'quota_health' => 0,
            ]);

            SubscriptionModel::create([
                'id' => $subscriptionId,
                'client_id' => $clientId,
                'deployment_id' => $deploymentId,
                'plan' => $plan->name,
                'price_monthly' => $plan->price_monthly,
                'billing_status' => 'pending',
                'auto_suspend' => false,
                'started_at' => $now,
            ]);

            foreach ($plan->quotaLimits() as $metric => $limit) {
                // Espejo para el SPA (tab de cuotas del Commander)…
                QuotaModel::create([
                    'id' => "{$deploymentId}:{$metric}",
                    'deployment_id' => $deploymentId,
                    'metric_key' => $metric,
                    'current_value' => 0,
                    'limit_value' => $limit,
                    'on_exceed' => $limit !== null ? 'suspend' : 'none',
                    'unit' => self::QUOTA_UNITS[$metric] ?? 'count',
                ]);

                // …y la fila real que el medidor de cuotas de Core hace cumplir, por package.
                CoreQuotaMetricModel::create([
                    'package_id' => $corePackage->id,
                    'metric_key' => $metric,
                    'limit_value' => $limit,
                    'current_value' => 0,
                    'on_exceed' => $limit !== null ? 'suspend' : 'none',
                ]);
            }

            return [$deployment, $corePackage];
        });

        // Crea la BD propia del package y migra/siembra ahi (DDL fuera de la transaccion central):
        // base Laravel del tenant + tablas de negocio + admin. Aislamiento fisico por BD.
        // (sqlite no tiene CREATE DATABASE ni multi-schema: en tests solo se valida la parte central.)
        if (DB::connection('central')->getDriverName() !== 'sqlite') {
            DB::connection('central')->statement(
                "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );

            // package = BD por convencion: el comando deriva la BD de --package (snake).
            Artisan::call('core:migrate', ['--connection' => 'tenant', '--package' => $package, '--force' => true]);
            Artisan::call('core:seed', ['--connection' => 'tenant', '--package' => $package, '--force' => true]);
        }

        return response()->json([
            'deployment' => $deployment->fresh()->load(['client', 'subscription', 'quotas', 'corePackage']),
        ], 201);
    }
}
