<?php

namespace Packages\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuthorModel extends Model
{
    protected $table = 'blog_authors';

    protected $guarded = [];

    public function posts(): HasMany
    {
        return $this->hasMany(PostModel::class, 'author_id');
    }
}
