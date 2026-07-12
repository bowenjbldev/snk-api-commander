<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Packages\Core\Models\CoreUserModel;

// Snapshot inmutable de un documento del builder antes de un PUT.
class SnkFolioLayoutRevisionModel extends Model
{
    protected $table = 'folio_layout_revisions';

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
