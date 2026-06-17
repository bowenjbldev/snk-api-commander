<?php

namespace Packages\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentModel extends Model
{
    protected $table = 'blog_comments';

    protected $guarded = [];

    protected $casts = [
        'approved' => 'boolean',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(PostModel::class, 'post_id');
    }
}
