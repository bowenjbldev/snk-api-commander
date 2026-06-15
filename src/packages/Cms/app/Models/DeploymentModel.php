<?php

namespace Packages\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeploymentModel extends Model
{
    protected $table = 'cms_deployments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    // Never expose the raw secret or the flat state columns; the SPA shape uses a nested
    // `state` object + `control_secret_suffix` (both appended below).
    protected $hidden = [
        'control_secret',
        'status',
        'reason',
        'message',
        'grace_until',
        'changed_by',
        'changed_at',
    ];

    protected $appends = [
        'state',
        'control_secret_suffix',
    ];

    protected function casts(): array
    {
        return [
            'control_secret' => 'encrypted',
            'control_secret_rotated_at' => 'datetime',
            'last_seen' => 'datetime',
            'grace_until' => 'datetime',
            'changed_at' => 'datetime',
            'requests_24h' => 'integer',
            'quota_health' => 'float',
        ];
    }

    /** Nested deploy state matching the SPA's DeploymentState type. */
    public function getStateAttribute(): array
    {
        return [
            'status' => $this->attributes['status'] ?? 'active',
            'reason' => $this->attributes['reason'] ?? null,
            'message' => $this->attributes['message'] ?? null,
            'grace_until' => optional($this->grace_until)->toISOString(),
            'changed_by' => $this->attributes['changed_by'] ?? null,
            'changed_at' => optional($this->changed_at)->toISOString(),
        ];
    }

    /** Last 4 chars of the (decrypted) control secret — never the full value. */
    public function getControlSecretSuffixAttribute(): ?string
    {
        $secret = $this->control_secret;

        return $secret ? substr($secret, -4) : null;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientModel::class, 'client_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SubscriptionModel::class, 'subscription_id');
    }

    public function quotas(): HasMany
    {
        return $this->hasMany(QuotaModel::class, 'deployment_id');
    }
}
