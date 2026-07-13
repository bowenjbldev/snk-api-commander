<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Packages\Core\Models\CoreUserModel;

// Snapshot inmutable de un documento del builder antes de un PUT.
class SnkKilkanaLayoutRevisionModel extends Model
{
    protected $table = 'kilkana_layout_revisions';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'schema' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(CoreUserModel::class, 'user_id');
    }
}
