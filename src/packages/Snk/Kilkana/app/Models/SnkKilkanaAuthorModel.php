<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

// Firma editorial (puede vivir sin cuenta; user_id vincula a users cuando exista).
class SnkKilkanaAuthorModel extends Model
{
    protected $table = 'kilkana_authors';

    protected $guarded = [];

    protected $casts = ['socials' => 'array'];

    public function avatarMedia(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaMediaModel::class, 'avatar_media_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $author) {
            $author->slug = $author->slug ?: Str::slug($author->name);
        });
    }
}
