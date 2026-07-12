<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SnkFolioCategoryModel extends Model
{
    protected $table = 'folio_categories';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $category) {
            $category->slug = $category->slug ?: Str::slug($category->name);
        });
    }
}
