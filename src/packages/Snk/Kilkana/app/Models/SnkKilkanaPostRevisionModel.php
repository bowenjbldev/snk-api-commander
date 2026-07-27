<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Packages\Core\Models\CoreUserModel;
use Packages\Snk\Kilkana\Models\Concerns\SnkKilkanaPrunesRevisionsSupport;

// Snapshot inmutable de un post antes de un update; created_at lo pone la BD.
class SnkKilkanaPostRevisionModel extends Model
{
    use SnkKilkanaPrunesRevisionsSupport;

    protected $table = 'kilkana_post_revisions';

    public static function revisionOwnerKey(): string
    {
        return 'post_id';
    }

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaPostModel::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(CoreUserModel::class, 'user_id');
    }
}
