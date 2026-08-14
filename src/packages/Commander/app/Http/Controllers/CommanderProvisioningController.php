<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Packages\Commander\Jobs\CommanderProvisionInstanceJob;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderSubscriptionItemModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Commander\Services\CommanderApplicationRegistryService;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Support\CorePackageSelectorSupport;

#[Group('Provisioning')]
class CommanderProvisioningController extends CoreBaseApiController
{
    private const PROVISION_PERMISSION = 'provision instances';

    public function __construct(private readonly CommanderApplicationRegistryService $registry) {}

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission(self::PROVISION_PERMISSION)) {
            return $response;
        }

        $data = $request->validate([
            'plan_id' => 'required|integer|exists:commander_plans,id',
            'subscription_id' => 'nullable|integer|exists:commander_subscriptions,id',
            'admin_email' => 'required|email|max:255',
            'admin_password' => ['required', 'string', 'max:255', Password::min(12)->letters()->numbers()],
            'client_id' => 'nullable|integer|exists:commander_clients,id',
            'client' => 'nullable|array',
            'client.account_id' => 'sometimes|required|integer|exists:commander_accounts,id',
            'client.slug' => ['required_without:client_id', 'string', 'max:63', 'regex:/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', 'not_in:api,commander,www'],
            'client.name' => 'required_without:client_id|string|max:255',
            'client.email' => 'required_without:client_id|email|max:255',
            'client.notes' => 'nullable|string',
            'client.billing_email' => 'nullable|email|max:255',
            'client.phone' => 'nullable|string|max:50',
            'client.tax_id' => 'nullable|string|max:64',
            'client.payment_terms' => 'nullable|in:prepaid,net_15,net_30,net_60',
            'client.address_line1' => 'nullable|string|max:255',
            'client.address_line2' => 'nullable|string|max:255',
            'client.city' => 'nullable|string|max:128',
            'client.postal_code' => 'nullable|string|max:32',
            'client.country' => 'nullable|string|size:2',
        ]);

        if (empty($data['client_id']) && empty($data['client'])) {
            return response()->json(['error' => 'Provide client_id or a client object.'], 422);
        }

        $plan = CommanderPlanModel::query()->with(['application.metrics', 'limits.metric'])->findOrFail($data['plan_id']);
        if ($plan->status !== 'active' || $plan->application->status !== 'active'
            || $plan->application->code_status !== 'available') {
            return response()->json(['error' => 'The selected plan or application is inactive.'], 422);
        }

        $client = ! empty($data['client_id']) ? CommanderClientModel::find($data['client_id']) : null;
        if ($client !== null && $client->status !== 'active') {
            return response()->json(['error' => 'The selected client is inactive.'], 422);
        }

        $clientSlug = $client?->slug ?? ($data['client']['slug'] ?? null);
        if (! is_string($clientSlug) || ! CorePackageSelectorSupport::isValidClientSubdomain($clientSlug)) {
            return response()->json(['error' => 'Client slug is not a valid tenant subdomain.'], 422);
        }
        if (! $this->registry->hasImplementation($clientSlug, $plan->application->package_name)) {
            return response()->json(['error' => 'Application code is not deployed for this client.'], 422);
        }
        if ($client === null && CommanderClientModel::query()->where('slug', $clientSlug)->exists()) {
            return response()->json(['error' => "Client '{$clientSlug}' already exists."], 422);
        }
        if ($client !== null && CommanderInstanceModel::query()
            ->where('client_id', $client->id)
            ->where('application_id', $plan->application_id)
            ->exists()) {
            return response()->json(['error' => 'Client already has this application instance.'], 409);
        }

        $database = CorePackageSelectorSupport::databaseName($clientSlug, $plan->application->package_name);
        if (strlen($database) > 64) {
            return response()->json(['error' => 'Client and application produce a database name longer than 64 characters.'], 422);
        }
        CorePackageSelectorSupport::validatedDatabaseIdentifier($database);

        $instance = DB::connection('commander')->transaction(function () use ($data, $plan, $client, $clientSlug, $database) {
            $client ??= $this->createClient($data['client']);
            $subscription = isset($data['subscription_id'])
                ? CommanderSubscriptionModel::query()->lockForUpdate()->findOrFail($data['subscription_id'])
                : $this->createCatalogSubscription($client, $plan);

            $this->validateSubscription($subscription, $client, $plan);

            $instance = CommanderInstanceModel::create([
                'client_id' => $client->id,
                'application_id' => $plan->application_id,
                'database' => $database,
                'provisioning_status' => 'pending',
                'service_status' => 'active',
                'requested_at' => now(),
                'changed_at' => now(),
            ]);
            $instance->forceFill([
                'database_username' => 'tenant_'.$instance->id,
                'database_password' => Str::random(48),
            ])->save();

            CommanderSubscriptionItemModel::create([
                'subscription_id' => $subscription->id,
                'instance_id' => $instance->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'started_at' => now(),
            ]);
            $limits = $plan->limits->keyBy('metric_id');
            foreach ($plan->application->metrics->where('status', 'active') as $metric) {
                $limit = $limits->get($metric->id);
                CommanderQuotaModel::create([
                    'instance_id' => $instance->id,
                    'metric_id' => $metric->id,
                    'current_value' => 0,
                    'limit_value' => $limit?->limit_value,
                    'warning_percent' => $limit?->warning_percent ?? 80,
                    'action' => $limit?->action ?? 'none',
                    'consecutive_periods' => $limit?->consecutive_periods ?? 1,
                ]);
            }

            CommanderProvisionInstanceJob::dispatch(
                $instance->id,
                $clientSlug,
                $plan->application->package_name,
                $data['admin_email'],
                Crypt::encryptString($data['admin_password']),
            )->afterCommit();

            return $instance;
        });

        return response()->json([
            'instance' => $instance->fresh()->load([
                'client.account', 'application', 'subscriptionItem.subscription', 'subscriptionItem.plan', 'quotas.metric',
            ]),
        ], 202);
    }

    /** @param array<string, mixed> $data */
    private function createClient(array $data): CommanderClientModel
    {
        $accountId = $data['account_id'] ?? CommanderAccountModel::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'billing_email' => $data['billing_email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? null,
        ])->id;

        return CommanderClientModel::create([
            'account_id' => $accountId,
            'slug' => $data['slug'],
            'name' => $data['name'],
            'email' => $data['email'],
            'notes' => $data['notes'] ?? null,
            'status' => 'active',
        ]);
    }

    private function createCatalogSubscription(CommanderClientModel $client, CommanderPlanModel $plan): CommanderSubscriptionModel
    {
        return CommanderSubscriptionModel::create([
            'account_id' => $client->account_id,
            'application_id' => $plan->application_id,
            'name' => $client->name.' · '.$plan->application->name,
            'pricing_mode' => 'catalog',
            'monthly_amount' => $plan->price_monthly,
            'currency' => 'EUR',
            'status' => 'active',
            'max_instances' => 1,
            'started_at' => now(),
        ]);
    }

    private function validateSubscription(
        CommanderSubscriptionModel $subscription,
        CommanderClientModel $client,
        CommanderPlanModel $plan,
    ): void {
        abort_if($subscription->account_id !== $client->account_id, 422, 'Subscription belongs to another account.');
        abort_if($subscription->application_id !== $plan->application_id, 422, 'Subscription belongs to another application.');
        abort_if(! in_array($subscription->status, ['active', 'trialing'], true), 422, 'Subscription is not active.');

        $activeItems = $subscription->items()->where('status', 'active')->count();
        abort_if($subscription->max_instances !== null && $activeItems >= $subscription->max_instances, 422, 'Subscription has no available instance slots.');
    }
}
