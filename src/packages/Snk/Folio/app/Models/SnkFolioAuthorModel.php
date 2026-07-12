<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

// Firma editorial (puede vivir sin cuenta; user_id vincula a users cuando exista).
class SnkFolioAuthorModel extends Model
{
    protected $table = 'folio_authors';

    protected $guarded = [];

    protected $casts = ['socials' => 'array'];

    public function avatarMedia(): BelongsTo
    {
        return $this->belongsTo(SnkFolioMediaModel::class, 'avatar_media_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $author) {
            $author->slug = $author->slug ?: Str::slug($author->name);
        });
    }
}
