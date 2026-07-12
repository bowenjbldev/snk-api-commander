<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SnkFolioTagModel extends Model
{
    protected $table = 'folio_tags';

    protected $guarded = [];

    // El pivot no aporta nada al JSON del post.
    protected $hidden = ['pivot'];

    protected static function booted(): void
    {
        static::creating(function (self $tag) {
            $tag->slug = $tag->slug ?: Str::slug($tag->name);
        });
    }
}
