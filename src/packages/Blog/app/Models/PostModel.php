<?php

namespace Packages\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostModel extends Model
{
    protected $table = 'blog_posts';

    protected $guarded = [];

    protected $casts = [
        'views' => 'integer',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(AuthorModel::class, 'author_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CommentModel::class, 'post_id');
    }
}
