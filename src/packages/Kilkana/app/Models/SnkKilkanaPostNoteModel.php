<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Packages\Core\Models\CoreUserModel;

/** Una nota de mesa: quién, sobre qué bloque, qué, y si ya está resuelta. */
class SnkKilkanaPostNoteModel extends Model
{
    protected $table = 'kilkana_post_notes';

    protected $guarded = [];

    protected $casts = [
        'done' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(CoreUserModel::class, 'user_id');
    }
}
