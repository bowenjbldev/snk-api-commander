<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Packages\Core\Models\CoreUserModel;

class SnkFolioCommentModel extends Model
{
    protected $table = 'folio_comments';

    protected $guarded = [];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SnkFolioPostModel::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(CoreUserModel::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
