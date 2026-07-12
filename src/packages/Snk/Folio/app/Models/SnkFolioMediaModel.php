<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SnkFolioMediaModel extends Model
{
    protected $table = 'folio_media';

    protected $guarded = [];

    // El hash no aporta nada al JSON: es solo el índice UNIQUE de dedup.
    protected $hidden = ['url_hash'];

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
}
