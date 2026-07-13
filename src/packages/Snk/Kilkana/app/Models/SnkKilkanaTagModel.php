<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SnkKilkanaTagModel extends Model
{
    protected $table = 'kilkana_tags';

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
