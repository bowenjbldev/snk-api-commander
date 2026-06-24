<?php

namespace Packages\Commander\Http\Controllers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Support\CorePackageSelectorSupport;
use Packages\Commander\Models\CommanderPackageModel;

/**
 * Client-first provisioning, EN UN PASO e in-process: registra CLIENTE (nuevo o existente) + PLAN,
 * crea la instancia (fila commander_packages = subdominio activo), su suscripcion y cuotas, migra
 * las tablas del package sobre su BD propia y siembra su admin. El codigo del package ya viaja por
 * git; aqui solo se da de alta la instancia.
 */
#[Group('Provisioning')]
class CommanderProvisioningController extends CoreBaseApiController
{
    private const PROVISION_PERMISSION = 'provision packages';

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
            'plan_id' => 'required|integer|exists:commander_plans,id',
            // Existing client …
            'client_id' => 'nullable|integer|exists:commander_clients,id',
            // … or a new client object (required unless client_id given). client.slug = handle legible;
            // el id (bigint) lo asigna la BD.
            'client' => 'nullable|array',
            'client.slug' => 'required_without:client_id|string|max:64',
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

        $plan = CommanderPlanModel::findOrFail($data['plan_id']);
        $package = Str::studly($plan->package);          // Shop
        $slug = CorePackageSelectorSupport::packageToSubdomain($package); // shop (segmento de path)

        // El cliente se resuelve PRIMERO: su slug forma la carpeta de código anidada y la BD.
        $clientSlug = ! empty($data['client_id'])
            ? CommanderClientModel::whereKey($data['client_id'])->value('slug')
            : ($data['client']['slug'] ?? null);

        if ($clientSlug === null) {
            return response()->json(['error' => 'Could not resolve client slug.'], 422);
        }

        $clientStudly = Str::studly($clientSlug);
        $database = CorePackageSelectorSupport::databaseName($clientSlug, $package); // acme_shop

        // El código del package per-cliente debe existir en disco (viaja por git, no se genera aquí):
        // packages/{Client}/{Package} (p.ej. packages/Acme/Shop).
        if (! is_dir(base_path("packages/{$clientStudly}/{$package}"))) {
            return response()->json(['error' => "Package code '{$clientStudly}/{$package}' is not deployed."], 422);
        }

        // Conflicto: ese cliente YA tiene una instancia viva de este package (client_id, slug).
        if (! empty($data['client_id'])
            && CommanderPackageModel::query()
                ->whereNotNull('provisioned_at')
                ->where('client_id', $data['client_id'])
                ->where('slug', $slug)
                ->exists()) {
            return response()->json(['error' => "Client already has package '{$slug}' provisioned."], 409);
        }

        if (! empty($data['client']) && CommanderClientModel::where('slug', $data['client']['slug'])->exists()) {
            return response()->json(['error' => "Client '{$data['client']['slug']}' already exists."], 422);
        }

        $commanderPackage = DB::connection('commander')->transaction(function () use ($data, $plan, $package, $slug, $database) {
            $now = CarbonImmutable::now();

            $clientId = $data['client_id'] ?? null;
            if ($clientId === null) {
                $c = $data['client'];
                $client = CommanderClientModel::create(array_merge(
                    ['slug' => $c['slug'], 'name' => $c['name'], 'email' => $c['email'], 'status' => 'active'],
                    array_intersect_key($c, array_flip([
                        'company', 'phone', 'billing_email', 'tax_id', 'address_line1',
                        'address_line2', 'city', 'postal_code', 'country', 'payment_terms',
                    ])),
                ));
                $clientId = $client->id; // bigint asignado por la BD
            }

            // Instancia viva 1:1 por (cliente, package). El de-provision soft conserva la fila; re-provisionar
            // reusa la del par (client_id, slug). provisioned_at != null la enciende (la resuelve el middleware).
            $commanderPackage = CommanderPackageModel::firstOrNew(['client_id' => $clientId, 'slug' => $slug]);
            $commanderPackage->name = $package;
            $commanderPackage->label = $commanderPackage->label ?? $package;
            $commanderPackage->listing_status = $commanderPackage->listing_status ?? 'active';
            $commanderPackage->forceFill([
                'database' => $database,
                'client_id' => $clientId,
                'slug' => $slug,
                'status' => 'active',
                'reason' => null,
                'message' => null,
                'grace_until' => null,
                'changed_at' => $now,
                'requests_24h' => 0,
                'quota_health' => 0,
                'last_seen' => null,
                'provisioned_at' => $now,
            ]);
            $commanderPackage->save();

            // Primer término de subscripción (vigente: ended_at null). package_id es la relación
            // autoritativa; plan_id traza el catálogo; plan/price_monthly son snapshots congelados.
            $subscription = CommanderSubscriptionModel::create([
                'client_id' => $clientId,
                'package_id' => $commanderPackage->id,
                'plan_id' => $plan->id,
                'plan' => $plan->name,
                'price_monthly' => $plan->price_monthly,
                'billing_status' => 'pending',
                'auto_suspend' => false,
                'started_at' => $now,
                'ended_at' => null,
            ]);
            $commanderPackage->subscription_id = $subscription->id;
            $commanderPackage->save();

            foreach ($plan->quotaLimits() as $metric => $limit) {
                // Tabla unica: una fila por metrica, que el panel edita y el medidor mide/aplica.
                CommanderQuotaModel::create([
                    'package_id' => $commanderPackage->id,
                    'metric_key' => $metric,
                    'current_value' => 0,
                    'limit_value' => $limit,
                    'on_exceed' => $limit !== null ? 'suspend' : 'none',
                    'unit' => self::QUOTA_UNITS[$metric] ?? 'count',
                ]);
            }

            return $commanderPackage;
        });

        // Crea la BD propia del package y migra/siembra ahi (DDL fuera de la transaccion commander):
        // base Laravel del tenant + tablas de negocio + admin. Aislamiento fisico por BD.
        // (sqlite no tiene CREATE DATABASE ni multi-schema: en tests solo se valida la parte commander.)
        if (DB::connection('commander')->getDriverName() !== 'sqlite') {
            DB::connection('commander')->statement(
                "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );

            // --client + --package => TENANT anidado; la BD se deriva snake(client)_snake(package).
            Artisan::call('core:migrate', ['--client' => $clientSlug, '--package' => $package, '--force' => true]);
            Artisan::call('core:seed', ['--client' => $clientSlug, '--package' => $package, '--force' => true]);
        }

        return response()->json([
            'package' => $commanderPackage->fresh()->load(['client', 'subscription', 'quotas']),
        ], 201);
    }
}
