<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Post normalizado: categoría/autor/portada por FK, tags por pivot y meta clave-valor.
// El JSON expone las relaciones anidadas (category, author, cover, tags, meta);
// las FKs crudas y la relación interna coverMedia/metaRows quedan ocultas.
class SnkFolioPostModel extends Model
{
    protected $table = 'folio_posts';

    protected $guarded = [];

    protected $with = ['category', 'author', 'coverMedia', 'tags', 'metaRows'];

    protected $hidden = ['category_id', 'author_id', 'cover_media_id', 'coverMedia', 'metaRows'];

    protected $appends = ['cover', 'meta'];

    protected function casts(): array
    {
        return [
            'body_blocks' => 'array',
            'featured' => 'boolean',
            'published_at' => 'datetime',
            'reads' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SnkFolioCategoryModel::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(SnkFolioAuthorModel::class, 'author_id');
    }

    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(SnkFolioMediaModel::class, 'cover_media_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(SnkFolioTagModel::class, 'folio_post_tag', 'post_id', 'tag_id');
    }

    public function metaRows(): HasMany
    {
        return $this->hasMany(SnkFolioPostMetaModel::class, 'post_id');
    }

    /** La portada serializada como `cover` (objeto media o null). */
    public function getCoverAttribute(): ?SnkFolioMediaModel
    {
        return $this->coverMedia;
    }

    /** Meta aplanada `{clave: valor}` para el JSON. */
    public function getMetaAttribute(): array
    {
        return $this->metaRows->pluck('meta_value', 'meta_key')->all();
    }
}
