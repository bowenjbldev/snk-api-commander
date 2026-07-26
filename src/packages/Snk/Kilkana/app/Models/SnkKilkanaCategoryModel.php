<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SnkKilkanaCategoryModel extends Model
{
    protected $table = 'kilkana_categories';

    protected $guarded = [];

    /**
     * La madre viaja siempre, pero como SLUG, no como objeto anidado.
     *
     * El cliente pregunta «¿este artículo está en Sports?» con un slug, y un `parent_id`
     * numérico no se puede casar contra eso sin tener el catálogo entero delante — algo
     * que el render SSR de la portada no tiene. Con `parent_slug`, pertenecer a una
     * sección es una comparación de strings sobre el propio post.
     *
     * El eager load termina solo: la madre de una raíz es null.
     */
    protected $with = ['parent'];

    protected $hidden = ['parent'];

    protected $appends = ['parent_slug', 'parent_name'];

    public function getParentSlugAttribute(): ?string
    {
        return $this->parent?->slug;
    }

    /** El par completo, simétrico con slug/name: el slug es el href y el nombre el texto.
     *  Con los dos, un post que viaja al cliente sabe pintar «Deportes › Fútbol» y
     *  enlazar ambos niveles sin consultar el catálogo de secciones. */
    public function getParentNameAttribute(): ?string
    {
        return $this->parent?->name;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    protected static function booted(): void
    {
        static::creating(function (self $category) {
            $category->slug = $category->slug ?: Str::slug($category->name);
        });
    }
}
