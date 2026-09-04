<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un número del boletín. Qué guarda cada columna lo dice la migración. */
class SnkKilkanaNewsletterIssueModel extends Model
{
    protected $table = 'kilkana_newsletter_issues';

    protected $guarded = [];

    protected $casts = [
        'post_ids' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'test_sent_at' => 'datetime',
    ];

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    /** Cuántas piezas lleva un número: las últimas publicadas al enviarlo. */
    public const PIECES = 4;

    public function events(): HasMany
    {
        return $this->hasMany(SnkKilkanaNewsletterEventModel::class, 'issue_id');
    }

    public function sent(): bool
    {
        return $this->status === self::SENT;
    }
}
