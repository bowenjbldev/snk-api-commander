<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Packages\Core\Models\CoreUserModel;

// Snapshot inmutable de un post antes de un update; created_at lo pone la BD.
class SnkFolioPostRevisionModel extends Model
{
    protected $table = 'folio_post_revisions';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SnkFolioPostModel::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(CoreUserModel::class, 'user_id');
    }
}
