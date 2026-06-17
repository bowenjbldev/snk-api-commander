<?php

namespace Packages\Commander\Http\Controllers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Commander\Models\ClientModel;
use Packages\Commander\Models\DeploymentModel;
use Packages\Commander\Models\PlanModel;
use Packages\Commander\Models\QuotaModel;
use Packages\Commander\Models\SubscriptionModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

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

    /**
     * Client-first provisioning: from the Commander, register a CLIENT (new or existing) and associate
     * a catalog PLAN. Creates the desired subscription as a 'provisioning' deployment + its subscription
     * + quota rows (limits from the plan). The real environment is NOT created here — the operator runs
     * `subscription.sh add <Package> --client=<id>` on the host, which activates this deployment.
     */
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
        $package = $plan->package;
        $subdomain = $data['subdomain'] ?? Str::of($package)->snake()->replace('_', '-')->toString();
        $deploymentId = $subdomain;

        if (DeploymentModel::whereKey($deploymentId)->exists()) {
            return response()->json(['error' => "A deployment '{$deploymentId}' already exists."], 409);
        }

        if (! empty($data['client']) && ClientModel::whereKey($data['client']['id'])->exists()) {
            return response()->json(['error' => "Client '{$data['client']['id']}' already exists."], 422);
        }

        $baseDomain = (string) config('commander.commander.base_domain', 'snake.test');
        $fqdn = "{$subdomain}.{$baseDomain}";
        $plannedBaseUrl = "https://{$fqdn}/api/v1";

        $deployment = DB::transaction(function () use ($data, $plan, $package, $subdomain, $deploymentId, $fqdn, $plannedBaseUrl) {
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

            $subscriptionId = 'sub_' . Str::lower(Str::random(20));

            $deployment = DeploymentModel::create([
                'id' => $deploymentId,
                'name' => $deploymentId,
                'subdomain' => $fqdn,
                'client_id' => $clientId,
                'package' => $package,
                'version' => 'pending',
                'base_url' => $plannedBaseUrl, // planned; the host overwrites with the real one on activation
                'control_secret' => null,
                'subscription_id' => $subscriptionId,
                'requests_24h' => 0,
                'quota_health' => 0,
                'status' => 'provisioning',
                'reason' => null,
                'message' => 'Awaiting host provisioning.',
                'changed_by' => 'operator',
                'changed_at' => $now,
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
                QuotaModel::create([
                    'id' => "{$deploymentId}:{$metric}",
                    'deployment_id' => $deploymentId,
                    'metric_key' => $metric,
                    'current_value' => 0,
                    'limit_value' => $limit,
                    'on_exceed' => $limit !== null ? 'suspend' : 'none',
                    'unit' => self::QUOTA_UNITS[$metric] ?? 'count',
                ]);
            }

            return $deployment;
        });

        return response()->json([
            'deployment' => $deployment->load(['client', 'subscription', 'quotas']),
            'host_command' => "bash scripts/subscription.sh add {$package} --client={$deployment->client_id}",
        ], 201);
    }
}
