<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Packages\Snk\Kilkana\Enums\SnkKilkanaCategoryStatusEnum;
use Packages\Snk\Kilkana\Models\Concerns\SnkKilkanaRetiredSlugsSupport;

class SnkKilkanaCategoryModel extends Model
{
    use SnkKilkanaRetiredSlugsSupport;

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

    protected $casts = [
        'noindex' => 'boolean',
        'status' => SnkKilkanaCategoryStatusEnum::class,
    ];

    /**
     * Los mismos valores por defecto que la columna, pero del lado de PHP.
     *
     * Sin esto los pone la base y la instancia recién creada no los conoce: la respuesta del
     * alta salía sin `status` ni `noindex`, y quien la pintara no sabía si la sección acababa
     * de nacer en preparación o si el campo no existía.
     */
    protected $attributes = [
        'status' => SnkKilkanaCategoryStatusEnum::Draft->value,
        'noindex' => false,
    ];

    /** Las que existen para el lector. Un solo sitio define «sección pública». */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', SnkKilkanaCategoryStatusEnum::Live);
    }

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

    /** Las URLs que esta sección ha dejado atrás; el trait las mantiene. */
    public function retiredSlugs(): HasMany
    {
        return $this->hasMany(SnkKilkanaCategorySlugModel::class, 'category_id');
    }

    public static function retiredSlugModel(): string
    {
        return SnkKilkanaCategorySlugModel::class;
    }

    public static function retiredSlugOwnerKey(): string
    {
        return 'category_id';
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * Tope del slug, y de aquí sale el ancho de la columna.
     *
     * 64 basta de sobra para un segmento de URL de una sección —«internacional» son 14— y es
     * lo que impide que un nombre largo derive un slug que no cabe. `name` valida hasta 255,
     * así que sin tope un nombre de 85 caracteres tumbaba el alta con un `1406 Data too long`.
     */
    public const SLUG_MAX = 64;

    /** Recorta sin dejar el guion colgando, que daría `mi-seccion-` de URL. */
    private static function capSlug(string $slug): string
    {
        return rtrim(mb_substr($slug, 0, self::SLUG_MAX), '-');
    }

    protected static function booted(): void
    {
        static::creating(function (self $category) {
            $category->slug = $category->slug
                ? self::capSlug((string) $category->slug)
                : self::capSlug(Str::slug((string) $category->name));

            // `slugTaken` mira las vivas Y las retiradas: una URL que redirige no está libre.
            // Sin esto, crear una sección con un nombre que ya existía devolvía el 1062 con
            // el nombre del índice dentro en vez de decir qué pasaba.
            if (! $category->slug || self::slugTaken($category->slug)) {
                throw ValidationException::withMessages([
                    'slug' => 'That section URL is already in use, now or by a redirect; choose another.',
                ]);
            }
        });

        static::saving(function (self $category) {
            self::guardTree($category);
            self::guardStatus($category);
        });
    }

    /**
     * No se devuelve a preparacion una seccion que ya tiene piezas publicadas.
     *
     * Es la otra mitad del muro que impide publicar en una seccion sin lanzar: sin esto se
     * llega al mismo sitio por la puerta de atras —lanzar la mesa, publicar en ella, y
     * despues retirarla— y esas piezas quedan colgando de una seccion que el lector no puede
     * abrir, con su kicker enlazando a una pagina que ya no existe.
     */
    private static function guardStatus(self $category): void
    {
        if (! $category->exists || ! $category->isDirty('status')) {
            return;
        }
        if ($category->status === SnkKilkanaCategoryStatusEnum::Live) {
            return;
        }

        $publicadas = SnkKilkanaPostModel::query()
            ->where('category_id', $category->id)
            ->published()
            ->count();

        if ($publicadas > 0) {
            throw ValidationException::withMessages([
                'status' => "This section has {$publicadas} published article(s); move or unpublish them first.",
            ]);
        }
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
