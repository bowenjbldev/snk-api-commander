<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SnkKilkanaMediaModel extends Model
{
    use SoftDeletes;

    protected $table = 'kilkana_media';

    protected $guarded = [];

    // El hash no aporta nada al JSON: es solo el índice UNIQUE de dedup.
    protected $hidden = ['url_hash'];

    // usage_count viaja siempre en el JSON; el controlador hace withCount para no pagar N+1.
    protected $appends = ['usage_count'];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'integer',
        'size_bytes' => 'integer',
        'focal_x' => 'float',
        'focal_y' => 'float',
        'rights_expires_at' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $media) {
            if ($media->isDirty('url') || $media->url_hash === null) {
                $media->url_hash = sha1($media->url);
            }
        });
    }

    /** Dedup por hash de la url (la url misma no cabe en un índice UNIQUE). */
    public static function firstOrCreateByUrl(string $url): self
    {
        return self::firstOrCreate(['url_hash' => sha1($url)], ['url' => $url]);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(SnkKilkanaMediaTagModel::class, 'kilkana_media_media_tag', 'media_id', 'media_tag_id');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(SnkKilkanaMediaCollectionModel::class, 'kilkana_media_collection_media', 'media_id', 'media_collection_id');
    }

    /** Posts que usan esta pieza como portada — base del usage_count y del filtro "sin usar". */
    public function coveredPosts(): HasMany
    {
        return $this->hasMany(SnkKilkanaPostModel::class, 'cover_media_id');
    }

    /** covered_posts_count si el controlador lo cargó (withCount); si no, cuenta al vuelo. */
    public function getUsageCountAttribute(): int
    {
        return (int) ($this->attributes['covered_posts_count'] ?? $this->coveredPosts()->count());
    }
}
