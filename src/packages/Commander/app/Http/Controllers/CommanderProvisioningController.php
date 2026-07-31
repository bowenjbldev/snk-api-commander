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
use Packages\Commander\Jobs\CommanderProvisionPackageJob;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Commander\Services\CommanderPackageRegistryService;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Support\CorePackageSelectorSupport;

/**
 * Client-first provisioning: registra CLIENTE + PLAN, prepara instancia/suscripcion/cuotas y
 * encola el DDL. El worker migra y siembra la BD; solo entonces fija provisioned_at.
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

    public function __construct(private readonly CommanderPackageRegistryService $registry) {}

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission(self::PROVISION_PERMISSION)) {
            return $response;
        }

        $data = $request->validate([
            'plan_id' => 'required|integer|exists:commander_plans,id',
            'admin_email' => 'required|email|max:255',
            'admin_password' => ['required', 'string', 'max:255', Password::min(12)->letters()->numbers()],
            // Existing client …
            'client_id' => 'nullable|integer|exists:commander_clients,id',
            // … or a new client object (required unless client_id given). client.slug = handle legible;
            // el id (bigint) lo asigna la BD.
            'client' => 'nullable|array',
            'client.slug' => ['required_without:client_id', 'string', 'max:63', 'regex:/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/'],
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
        if ($plan->status !== 'active') {
            return response()->json(['error' => 'The selected plan is inactive.'], 422);
        }

        $package = Str::studly($plan->package);          // Shop
        $slug = CorePackageSelectorSupport::packageToSubdomain($package); // shop (segmento de path)

        // El cliente se resuelve PRIMERO: su slug forma la carpeta de código anidada y la BD.
        $existingClient = ! empty($data['client_id'])
            ? CommanderClientModel::query()->find($data['client_id'])
            : null;
        if ($existingClient !== null && $existingClient->status !== 'active') {
            return response()->json(['error' => 'The selected client is inactive.'], 422);
        }

        $clientSlug = $existingClient?->slug ?? ($data['client']['slug'] ?? null);

        if ($clientSlug === null) {
            return response()->json(['error' => 'Could not resolve client slug.'], 422);
        }

        if (! CorePackageSelectorSupport::isValidClientSubdomain($clientSlug)) {
            return response()->json(['error' => "Client slug '{$clientSlug}' is not a valid tenant subdomain."], 422);
        }

        $database = CorePackageSelectorSupport::databaseName($clientSlug, $package); // acme_shop

        if (strlen($database) > 64) {
            return response()->json(['error' => 'Client and package produce a database name longer than 64 characters.'], 422);
        }
        try {
            CorePackageSelectorSupport::validatedDatabaseIdentifier($database);
        } catch (\RuntimeException) {
            return response()->json(['error' => 'Client and package produce an invalid database name.'], 422);
        }

        if (! $this->registry->hasImplementation($clientSlug, $package)) {
            return response()->json(['error' => "Package code '{$package}' is not deployed as shared or client-specific code."], 422);
        }

        // Conflicto: ese cliente ya tiene esta instancia viva o preparandose.
        if (! empty($data['client_id'])
            && CommanderPackageModel::query()
                ->where('client_id', $data['client_id'])
                ->where('slug', $slug)
                ->exists()) {
            return response()->json(['error' => "Client already has package '{$slug}' registered."], 409);
        }

        if (! empty($data['client']) && CommanderClientModel::where('slug', $data['client']['slug'])->exists()) {
            return response()->json(['error' => "Client '{$data['client']['slug']}' already exists."], 422);
        }

        $provisioning = DB::connection('commander')->transaction(function () use ($data, $plan, $package, $slug, $database) {
            $now = CarbonImmutable::now();

            $clientId = $data['client_id'] ?? null;
            $createdClientId = null;
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
                $createdClientId = $clientId;
            }

            // La UNIQUE(client_id, slug) serializa intentos concurrentes del mismo alta.
            $commanderPackage = new CommanderPackageModel;
            $commanderPackage->name = $package;
            $commanderPackage->label = $commanderPackage->label ?? $package;
            $commanderPackage->listing_status = $commanderPackage->listing_status ?? 'active';
            $commanderPackage->forceFill([
                'database' => $database,
                'database_username' => null,
                'database_password' => null,
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
                // El resolutor ignora esta fila hasta que BD, migraciones y seed terminen bien.
                'provisioned_at' => null,
            ]);
            $commanderPackage->save();
            $commanderPackage->forceFill([
                'database_username' => 'tenant_'.$commanderPackage->id,
                'database_password' => Str::random(48),
            ])->save();

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

            return [
                'package' => $commanderPackage,
                'subscription_id' => $subscription->id,
                'created_client_id' => $createdClientId,
            ];
        });

        /** @var CommanderPackageModel $commanderPackage */
        $commanderPackage = $provisioning['package'];

        CommanderProvisionPackageJob::dispatch(
            $commanderPackage->id,
            $clientSlug,
            $package,
            $provisioning['created_client_id'],
            $data['admin_email'],
            Crypt::encryptString($data['admin_password']),
        );

        return response()->json([
            'package' => $commanderPackage->fresh()->load(['client', 'subscription', 'quotas']),
        ], 202);
    }
}
