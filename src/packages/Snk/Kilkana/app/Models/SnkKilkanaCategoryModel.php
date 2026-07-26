<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

        static::saving(fn (self $category) => self::guardTree($category));
    }

    /**
     * El arbol tiene DOS niveles y no admite ciclos, y aqui es donde se impone.
     *
     * No por comodidad: MariaDB no acepta subconsultas en un `CHECK`, asi que «tu madre no
     * puede tener madre» no se puede escribir como restriccion de la base. O vive en el
     * modelo o no vive en ninguna parte — y no vivia en ninguna parte:
     *
     *   - Se podia crear una nieta. No fallaba: la consulta de seccion mira la categoria y
     *     su madre, asi que los articulos del tercer nivel DESAPARECIAN del listado de la
     *     abuela sin un solo error.
     *   - Se podia cerrar un ciclo con dos PUT que devolvian 200 cada uno. El `$with` de la
     *     madre entraba en bucle y `public/categories` —la nav, las paginas de seccion, el
     *     sitemap— respondia 500 tras siete segundos.
     *
     * La validacion del controlador solo cubria `parent_id != id`, y solo al actualizar. El
     * seeder y la consola no pasan por ella; por eso la comprobacion va en el modelo.
     */
    private static function guardTree(self $category): void
    {
        if ($category->parent_id === null) {
            return; // una raiz no tiene nada que comprobar
        }

        $error = fn (string $mensaje) => throw ValidationException::withMessages(['parent_id' => $mensaje]);

        if ($category->exists && (int) $category->parent_id === (int) $category->id) {
            $error('A section cannot be its own parent.');
        }

        // Dos niveles: tu madre tiene que ser una raiz. Con esta sola regla los ciclos
        // tampoco caben —cerrar uno exigiria que las dos tuvieran madre— pero el id se
        // comprueba aparte, porque una fila que se apunta a si misma leeria de la base su
        // propio `parent_id` viejo y se colaria.
        if (self::query()->whereKey($category->parent_id)->value('parent_id') !== null) {
            $error('A subsection cannot hang from another subsection: the tree has two levels.');
        }

        // Y en el otro sentido: si ya eres madre, no puedes colgar de nadie. Sin esto, mover
        // una seccion con hijas bajo otra creaba el tercer nivel por la puerta de atras.
        if ($category->exists && self::query()->where('parent_id', $category->id)->exists()) {
            $error('A section with subsections cannot become a subsection itself.');
        }
    }
}
