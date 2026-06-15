<?php

namespace Packages\Commander\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Packages\Commander\Models\ActivityEventModel;
use Packages\Commander\Models\ClientModel;
use Packages\Commander\Models\DeploymentModel;
use Packages\Commander\Models\InvoiceModel;
use Packages\Commander\Models\QuotaModel;
use Packages\Commander\Models\SubscriptionModel;
use Packages\Commander\Models\UsageSnapshotModel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds the platform registry with the same coherent demo dataset the SPA ships as
 * fixtures (commander/src/store/fixtures.ts), so the wired SPA shows identical data.
 */
class CommanderDatabaseSeeder extends Seeder
{
    private const S = 1;
    private const M = 60;
    private const H = 3600;
    private const D = 86400;

    public function run(): void
    {
        $this->seedPermissions();

        $now = Carbon::now();
        $ago = fn (int $sec): Carbon => (clone $now)->subSeconds($sec);
        $ahead = fn (int $sec): Carbon => (clone $now)->addSeconds($sec);

        $this->seedClients();
        $this->seedDeployments($now, $ago, $ahead);
        $this->seedSubscriptions();
        $this->seedInvoices($ago);
        $this->seedQuotas($ago);
        $this->seedActivity($ago);
        $this->seedUsageSnapshots($now);
    }

    private function seedPermissions(): void
    {
        $resources = ['clients', 'deployments', 'subscriptions', 'invoices', 'quotas', 'activity'];
        $actions = ['index', 'show', 'create', 'update', 'delete'];
        $names = [];
        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                $names[] = "$action $resource";
            }
        }
        // Custom: signed /control proxy actions (suspend/grace/resume/sync).
        $names[] = 'control deployments';
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        $admin = Role::findOrCreate('admin', 'sanctum');
        $admin->givePermissionTo($names);
    }

    private function seedClients(): void
    {
        // [id, name, company, email, phone, notes, status, created_at]
        $rows = [
            ['acme', 'Acme Shop', 'Acme Retail GmbH', 'billing@acme-shop.de', '+49 30 1234 5678', 'Auto-suspend enabled. Primary contact prefers email.', 'active', '2024-03-04'],
            ['bluepeak', 'Bluepeak Analytics', 'Bluepeak Analytics Ltd', 'dev@bluepeak.io', '+44 20 7946 0991', 'High-volume metrics workload across EU + US regions.', 'active', '2024-08-19'],
            ['helios', 'Helios Energy', 'Helios Energy AG', 'api@helios.energy', '+41 44 668 1800', 'Telemetry bursts at hour boundaries. Watch requests_day.', 'active', '2025-05-12'],
            ['kestrel', 'Kestrel Logistics', 'Kestrel Logistics Co', 'it@kestrel-logistics.com', '+1 312 555 0142', 'Payment for INV-2026-0140 outstanding — in grace.', 'active', '2024-11-12'],
            ['mori', 'Mori Design Co', 'Mori Design Co', 'studio@mori.design', null, null, 'active', '2025-02-08'],
            ['northwind', 'Northwind Labs', 'Northwind Labs Inc', 'ops@northwindlabs.io', '+1 415 555 0177', 'Three deployments: prod ledger, batch runner, sandbox.', 'active', '2024-01-22'],
            ['quill', 'Quill & Co', 'Quill & Co Publishing', 'hello@quillandco.com', null, 'Suspended for abuse. Account under review.', 'inactive', '2023-06-15'],
            ['tetra', 'Tetra Robotics', 'Tetra Robotics SAS', 'eng@tetrarobotics.com', '+33 1 70 18 99 00', 'Fleet + telemetry. tetra-telemetry in manual grace.', 'active', '2025-10-03'],
        ];
        foreach ($rows as [$id, $name, $company, $email, $phone, $notes, $status, $created]) {
            ClientModel::updateOrCreate(['id' => $id], [
                'name' => $name, 'company' => $company, 'email' => $email, 'phone' => $phone,
                'notes' => $notes, 'status' => $status,
                'created_at' => Carbon::parse($created.' 10:00:00'), 'updated_at' => Carbon::now(),
            ]);
        }
    }

    private function seedDeployments(Carbon $now, callable $ago, callable $ahead): void
    {
        // [name, client, package, version, status, req24h, quotaHealth, lastSeenSec, suffix, rotatedDays, created]
        $rows = [
            ['acme-shop', 'acme', 'commerce-api', 'v2.4.1', 'suspended', 0, 0.64, 3 * self::H, '9b1c', 22, '2024-03-06'],
            ['acme-shop-staging', 'acme', 'commerce-api', 'v2.5.0-rc1', 'active', 1204, 0.12, 2 * self::M, 'a7e0', 9, '2025-09-01'],
            ['bluepeak', 'bluepeak', 'metrics-api', 'v4.0.2', 'active', 121008, 0.83, 8, '4d44', 12, '2024-08-20'],
            ['bluepeak-eu', 'bluepeak', 'metrics-api', 'v4.0.2', 'active', 96544, 0.71, 5, 'c19a', 12, '2024-09-15'],
            ['helios-archive', 'helios', 'archive-api', 'v1.0.4', 'active', 412, 0.06, 4 * self::M, '5520', 60, '2025-05-20'],
            ['helios-meter', 'helios', 'telemetry-api', 'v5.2.1', 'active', 1240332, 1.24, 2, 'f0a3', 5, '2025-05-12'],
            ['kestrel-track', 'kestrel', 'tracking-api', 'v2.9.3', 'grace', 18772, 0.44, 30, '3f2a', 41, '2024-11-12'],
            ['mori-assets', 'mori', 'asset-cdn', 'v1.2.0', 'active', 9341, 0.22, 1 * self::M, '7711', 30, '2025-02-10'],
            ['northwind-api', 'northwind', 'ledger-api', 'v3.1.0', 'active', 84210, 0.58, 12, 'b2c8', 18, '2024-01-25'],
            ['northwind-batch', 'northwind', 'batch-runner', 'v1.8.2', 'active', 6902, 0.31, 45, 'e4d1', 18, '2024-04-01'],
            ['northwind-sandbox', 'northwind', 'ledger-api', 'v3.1.0', 'active', 2318, 0.09, 70, '0c9f', 18, '2024-06-01'],
            ['quill-cms', 'quill', 'content-api', 'v3.3.0', 'suspended', 0, 0.38, 2 * self::D, '8a20', 75, '2023-06-20'],
            ['tetra-fleet', 'tetra', 'fleet-api', 'v6.0.0', 'active', 54109, 0.49, 10, 'd7b6', 14, '2025-10-05'],
            ['tetra-fleet-dev', 'tetra', 'fleet-api', 'v6.1.0-beta', 'active', 870, 0.04, 3 * self::M, '1e3c', 7, '2025-11-20'],
            ['tetra-telemetry', 'tetra', 'telemetry-api', 'v5.2.1', 'grace', 22450, 0.52, 18, 'aa51', 20, '2025-10-10'],
        ];
        $stateChanged = ['acme-shop' => 3 * self::H, 'quill-cms' => 2 * self::D, 'kestrel-track' => 14 * self::H, 'tetra-telemetry' => 26 * self::H];
        $graceRemaining = ['kestrel-track' => 13 * self::H + 42 * self::M, 'tetra-telemetry' => 61 * self::H + 8 * self::M];
        $withSub = ['acme-shop', 'bluepeak', 'helios-meter', 'kestrel-track', 'mori-assets', 'northwind-api', 'tetra-fleet', 'quill-cms', 'bluepeak-eu', 'tetra-telemetry'];
        $suspendMsg = 'Your subscription payment is overdue. The API has been suspended — settle the open balance to restore service immediately.';
        $graceMsg = 'Your subscription payment is overdue. Service continues during a short grace period; settle the balance to avoid suspension.';

        foreach ($rows as [$name, $client, $pkg, $version, $status, $req, $health, $seen, $suffix, $rotated, $created]) {
            $reason = null;
            $message = null;
            $graceUntil = null;
            $changedBy = 'cli';
            $changedAt = Carbon::parse($created.' 10:00:00');
            if ($status === 'suspended') {
                $reason = $name === 'quill-cms' ? 'abuse' : 'payment_overdue';
                $message = $name === 'quill-cms' ? 'This deployment has been suspended pending account review.' : $suspendMsg;
                $changedBy = $name === 'quill-cms' ? 'operator' : 'auto-suspend';
                $changedAt = $ago($stateChanged[$name] ?? self::H);
            } elseif ($status === 'grace') {
                $reason = $name === 'tetra-telemetry' ? 'manual' : 'payment_overdue';
                $message = $graceMsg;
                $changedBy = 'operator';
                $changedAt = $ago($stateChanged[$name] ?? self::H);
                $graceUntil = $ahead($graceRemaining[$name] ?? 72 * self::H);
            }

            DeploymentModel::updateOrCreate(['id' => $name], [
                'name' => $name,
                'subdomain' => "$name.snakecloud.io",
                'client_id' => $client,
                'package' => $pkg,
                'version' => $version,
                'base_url' => "https://$name.snakecloud.io/api/v1",
                'control_secret' => 'sk_ctl_live_'.bin2hex(random_bytes(8)).$suffix,
                'control_secret_rotated_at' => $ago($rotated * self::D),
                'subscription_id' => in_array($name, $withSub, true) ? "sub_$name" : null,
                'requests_24h' => $req,
                'quota_health' => $health,
                'last_seen' => $ago($seen),
                'status' => $status,
                'reason' => $reason,
                'message' => $message,
                'grace_until' => $graceUntil,
                'changed_by' => $changedBy,
                'changed_at' => $changedAt,
                'created_at' => Carbon::parse($created.' 10:00:00'),
                'updated_at' => $now,
            ]);
        }
    }

    private function seedSubscriptions(): void
    {
        // [deployment, plan, billing, price, autoSuspend]
        $rows = [
            ['acme-shop', 'Commerce Pro', 'overdue', 290, true],
            ['bluepeak', 'Metrics Scale', 'paid', 480, false],
            ['helios-meter', 'Telemetry Pro', 'pending', 520, false],
            ['kestrel-track', 'Tracking Pro', 'overdue', 180, true],
            ['mori-assets', 'CDN Basic', 'paid', 120, false],
            ['northwind-api', 'Ledger Pro', 'paid', 310, false],
            ['tetra-fleet', 'Fleet Pro', 'pending', 390, false],
            ['quill-cms', 'Content Standard', 'paid', 150, true],
            ['bluepeak-eu', 'Metrics Scale', 'paid', 480, false],
            ['tetra-telemetry', 'Telemetry Standard', 'paid', 260, false],
        ];
        foreach ($rows as [$dep, $plan, $billing, $price, $auto]) {
            $deployment = DeploymentModel::find($dep);
            SubscriptionModel::updateOrCreate(['id' => "sub_$dep"], [
                'client_id' => $deployment?->client_id ?? '',
                'deployment_id' => $dep,
                'plan' => $plan,
                'price_monthly' => $price,
                'billing_status' => $billing,
                'auto_suspend' => $auto,
                'started_at' => $deployment?->created_at,
            ]);
        }
    }

    private function seedInvoices(callable $ago): void
    {
        // [id, client, deployment, amount, issuedDaysAgo, dueDaysAgo(+overdue/-future), status, enforcement]
        $rows = [
            ['INV-2026-0145', 'helios', 'helios-meter', 520, 12, 2, 'pending', null],
            ['INV-2026-0144', 'bluepeak', 'bluepeak', 240, 7, -17, 'pending', null],
            ['INV-2026-0143', 'tetra', 'tetra-fleet', 390, 11, -13, 'pending', null],
            ['INV-2026-0142', 'northwind', 'northwind-api', 310, 20, 6, 'paid', null],
            ['INV-2026-0141', 'mori', 'mori-assets', 120, 15, 1, 'paid', null],
            ['INV-2026-0140', 'kestrel', 'kestrel-track', 180, 17, 3, 'overdue', 'grace'],
            ['INV-2026-0139', 'acme', 'acme-shop', 290, 23, 9, 'overdue', 'suspended'],
            ['INV-2026-0138', 'helios', 'helios-meter', 520, 42, 12, 'paid', null],
        ];
        foreach ($rows as [$id, $client, $dep, $amount, $issued, $due, $status, $enforcement]) {
            InvoiceModel::updateOrCreate(['id' => $id], [
                'client_id' => $client,
                'deployment_id' => $dep,
                'amount' => $amount,
                'issued_at' => $ago($issued * self::D),
                'due_at' => $ago($due * self::D),
                'status' => $status,
                'enforcement' => $enforcement,
            ]);
        }
    }

    private function seedQuotas(callable $ago): void
    {
        $usersLimited = ['bluepeak', 'northwind-api', 'tetra-fleet'];
        foreach (DeploymentModel::all() as $d) {
            $metrics = $this->quotasFor($d, $usersLimited, $ago);
            foreach ($metrics as $m) {
                QuotaModel::updateOrCreate(['id' => $d->id.':'.$m['metric_key']], array_merge($m, [
                    'deployment_id' => $d->id,
                ]));
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function quotasFor(DeploymentModel $d, array $usersLimited, callable $ago): array
    {
        if ($d->id === 'kestrel-track') {
            return [
                $this->q('requests_day', 201330, 500000, 'suspend', null, 'count'),
                $this->q('disk_db_mb', 1204, 4096, 'suspend', null, 'mb'),
                $this->q('disk_storage_mb', 8920, 20480, 'none', null, 'mb'),
                $this->q('users_total', 322, null, 'none', null, 'count'),
            ];
        }
        if ($d->id === 'helios-meter') {
            return [
                $this->q('requests_day', 1240332, 1000000, 'warn', $ago(3 * self::D), 'count'),
                $this->q('disk_db_mb', 7420, 10240, 'suspend', null, 'mb'),
                $this->q('disk_storage_mb', 12880, 20480, 'none', null, 'mb'),
                $this->q('users_total', 1840, null, 'none', null, 'count'),
            ];
        }
        $h = (float) $d->quota_health;
        $serving = $d->requests_24h > 0;
        $clamp = fn (float $v, float $lo, float $hi): float => min($hi, max($lo, $v));
        $roundTo = fn (float $v, int $step): int => max($step, (int) (round($v / $step) * $step));

        $reqLimit = $serving ? $roundTo($d->requests_24h / max($h, 0.01), 1000) : 500000;
        $stRatio = $serving ? $clamp($h * 0.8, 0.04, 0.98) : $h;

        return [
            $this->q('requests_day', $serving ? $d->requests_24h : 0, $reqLimit, 'suspend', null, 'count'),
            $this->q('disk_db_mb', (int) round($clamp($h * 0.55, 0.03, 0.95) * 4096), 4096, 'suspend', null, 'mb'),
            $this->q('disk_storage_mb', (int) round($stRatio * 20480), 20480, 'none', null, 'mb'),
            $this->q('users_total', (int) round(200 + $h * 1500), in_array($d->id, $usersLimited, true) ? 5000 : null, 'none', null, 'count'),
        ];
    }

    /** @return array<string, mixed> */
    private function q(string $metric, int $current, ?int $limit, string $onExceed, ?Carbon $exceededAt, string $unit): array
    {
        return [
            'metric_key' => $metric,
            'current_value' => $current,
            'limit_value' => $limit,
            'on_exceed' => $onExceed,
            'exceeded_at' => $exceededAt,
            'unit' => $unit,
        ];
    }

    private function seedActivity(callable $ago): void
    {
        $rows = [
            ['a1', 3 * self::H, 'suspend', 'acme-shop', 'INV-2026-0139', 'payment_overdue', 'auto-suspend', null],
            ['a2', 14 * self::H, 'grace', 'kestrel-track', null, 'payment_overdue', 'operator', null],
            ['a3', 1 * self::D, 'paid', 'mori-assets', 'INV-2026-0141', null, null, 'Mori Design Co · $120.00'],
            ['a4', 26 * self::H, 'grace', 'tetra-telemetry', null, 'manual', 'operator', null],
            ['a5', 2 * self::D, 'suspend', 'quill-cms', null, 'abuse', 'operator', null],
            ['a6', 3 * self::D, 'quota', 'helios-meter', null, null, null, 'exceeded requests_day · policy warn'],
        ];
        foreach ($rows as [$id, $atAgo, $kind, $dep, $invoice, $reason, $by, $message]) {
            ActivityEventModel::updateOrCreate(['id' => $id], [
                'at' => $ago($atAgo),
                'kind' => $kind,
                'deployment_id' => $dep,
                'invoice_id' => $invoice,
                'reason' => $reason,
                'by' => $by,
                'message' => $message,
            ]);
        }
    }

    /**
     * 48 hourly usage snapshots per deployment over the last 48h — a deterministic diurnal curve
     * from requests_24h, errors scaled by quota_health (over-quota deploys show elevated 4xx/5xx).
     * Populates the platform charts out of the box; a live `sync` overwrites a deploy's hours.
     */
    private function seedUsageSnapshots(Carbon $now): void
    {
        $hourStart = (clone $now)->startOfHour();

        foreach (DeploymentModel::all() as $d) {
            $base = $d->requests_24h / 24;
            $errRate = min(0.08, max(0.004, (float) $d->quota_health * 0.03));

            for ($h = 0; $h < 48; $h++) {
                $bucket = (clone $hourStart)->subHours(47 - $h);
                $hour = (int) $bucket->format('G');
                $wave = 1 + 0.4 * sin(($hour / 24) * M_PI * 2 - M_PI / 2);
                $noise = (abs(crc32($d->id.':'.$h)) % 1000) / 1000.0; // deterministic 0..1
                $req = (int) max(0, round($base * $wave * (0.85 + $noise * 0.3)));
                $err = (int) round($req * $errRate * (0.6 + $noise * 0.8));

                UsageSnapshotModel::updateOrCreate(['id' => $d->id.':hour:'.$bucket->timestamp], [
                    'deployment_id' => $d->id,
                    'period' => 'hour',
                    'period_start' => $bucket,
                    'request_count' => $req,
                    'error_count' => min($req, $err),
                ]);
            }
        }
    }
}
