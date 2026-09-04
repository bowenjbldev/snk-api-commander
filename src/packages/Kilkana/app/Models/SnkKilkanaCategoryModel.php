<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Packages\Kilkana\Enums\SnkKilkanaCategoryStatusEnum;
use Packages\Kilkana\Models\Concerns\SnkKilkanaRetiredSlugsSupport;

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
        // No es una columna: la calcula el `withMax` del listado. Se declara igual para que
        // salga en ISO como el resto de fechas de la API — cruda sale «2026-08-26 06:00:00»,
        // que un cliente en otro huso lee como hora local suya y desplaza el dato.
        'last_published_at' => 'datetime',
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

    /**
     * Las que salen en la navegacion. Un solo sitio define «seccion del menu».
     *
     * No es lo mismo que existir para el lector: una `hidden` se abre por su URL y no
     * aparece aqui, que es exactamente para lo que existe ese estado. Quien pregunte por lo
     * segundo usa `reachable()`, y confundirlos publica en el menu lo que se retiro de el.
     */
    public function scopeInMenu(Builder $query): Builder
    {
        return $query->whereIn('status', self::statuses(fn ($estado) => $estado->inMenu()));
    }

    /** Las que el lector puede abrir por su URL: las del menu y las retiradas de el. */
    public function scopeReachable(Builder $query): Builder
    {
        return $query->whereIn('status', self::statuses(fn ($estado) => $estado->isPublic()));
    }

    /**
     * Los estados que contestan que si a una pregunta del enum.
     *
     * Los scopes preguntan y no enumeran a proposito: con la lista escrita aqui, un estado
     * nuevo entra en el enum y estos dos siguen contestando lo de ayer sin que nada falle.
     *
     * @param  callable(SnkKilkanaCategoryStatusEnum): bool  $filtro
     * @return list<string>
     */
    private static function statuses(callable $filtro): array
    {
        return array_values(array_map(
            fn (SnkKilkanaCategoryStatusEnum $estado) => $estado->value,
            array_filter(SnkKilkanaCategoryStatusEnum::cases(), $filtro)
        ));
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

    /**
     * Las piezas archivadas en esta seccion.
     *
     * Existe para poder CONTARLAS sin traerlas: el panel enseña «Local 40» y con una
     * relacion declarada eso es un `withCount` —una subconsulta— en vez de diecisiete
     * peticiones o un listado entero en el cliente. No se carga nunca entera: una seccion de
     * un diario de verdad tiene decenas de miles.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(SnkKilkanaPostModel::class, 'category_id');
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

        // Al crear, el slug se deriva mas arriba en este mismo evento: `saving` dispara antes y
        // aun lo veria vacio.
        static::creating(fn (self $category) => self::guardReservedSlug($category));
        static::updating(fn (self $category) => self::guardReservedSlug($category));

        static::saving(function (self $category) {
            self::guardTree($category);
            self::guardStatus($category);
            self::guardVisibility($category);
        });
    }

    /**
     * Los primeros segmentos que la calle tiene fijos: una seccion con ese slug se guarda y su
     * pagina publica no existe nunca, sin que nada avise. La calle esta en `kilkana.app`
     * (`src/routes`), asi que esta lista se mueve con la suya.
     */
    public const RESERVED_SLUGS = ['admin', 'account', 'author', 'tag', 'subscribe', 'csp-report'];

    /** La forma de un slug: minúsculas, dígitos y guiones. Sin punto, y por eso `robots.txt`, `rss.xml` y `sitemap.xml` no necesitan reservarse. */
    public const SLUG_SHAPE = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private static function guardReservedSlug(self $category): void
    {
        if (in_array($category->slug, self::RESERVED_SLUGS, true)) {
            throw ValidationException::withMessages([
                'slug' => 'That URL belongs to the site itself; choose another.',
            ]);
        }
    }

    /**
     * No se devuelve a preparacion una seccion que ya tiene piezas publicadas.
     *
     * Es la otra mitad del muro que impide publicar en una seccion sin lanzar: sin esto se
     * llega al mismo sitio por la puerta de atras —lanzar la mesa, publicar en ella, y
     * despues retirarla— y esas piezas quedan colgando de una seccion que el lector no puede
     * abrir, con su kicker enlazando a una pagina que ya no existe.
     *
     * Retirarla del menu si que se puede con el archivo lleno: `hidden` deja las piezas
     * abriendose por su URL, que es justo lo que `draft` no hace.
     *
     * Cuenta tambien lo que cuelga de sus subsecciones: el arbol tiene dos niveles y la madre
     * de una seccion viva no puede estar en preparacion.
     */
    private static function guardStatus(self $category): void
    {
        if (! $category->exists || ! $category->isDirty('status')) {
            return;
        }
        if ($category->status->isPublic()) {
            return;
        }

        // Las suyas Y las de sus subsecciones. Una madre sin piezas propias y con una hija
        // publicando pasaba a preparacion sin que nada la parara: la hija seguia alcanzable,
        // sus piezas publicas, y la pagina de la madre respondia 404.
        $publicadas = SnkKilkanaPostModel::query()
            ->whereIn('category_id', [
                $category->id,
                ...self::query()->where('parent_id', $category->id)->pluck('id')->all(),
            ])
            ->published()
            ->count();

        if ($publicadas > 0) {
            throw ValidationException::withMessages([
                'status' => "This section has {$publicadas} published article(s); move or unpublish them first.",
            ]);
        }
    }

    /**
     * Una seccion no se ve mas que su madre, y esto cierra las dos puertas.
     *
     * `guardStatus` cierra la del estado —no devuelvas a preparacion lo que tiene publicados—
     * pero la de la jerarquia quedaba abierta: mover una seccion viva con archivo bajo una
     * madre en preparacion pasaba limpio, y se llegaba al mismo sitio prohibido. La forma la
     * comprueba `guardTree`; el estado, esto.
     *
     * Y no es solo `draft`: una hija `live` bajo una madre `hidden` tampoco esta en el menu de
     * nadie, porque el suyo cuelga de un desplegable que ya no se pinta. Por eso se compara la
     * escala del enum y no un booleano.
     *
     * Se mira en las dos direcciones porque las dos escrituras llevan al mismo estado
     * imposible: bajar el estado de una madre con hijas mas visibles, y colgar una hija de una
     * madre menos visible.
     */
    private static function guardVisibility(self $category): void
    {
        if (! $category->isDirty(['status', 'parent_id'])) {
            return;
        }

        $suya = $category->status->visibility();

        if ($category->parent_id !== null) {
            $campo = $category->isDirty('parent_id') ? 'parent_id' : 'status';
            $madre = self::query()->whereKey($category->parent_id)->value('status');
            $madre = $madre instanceof SnkKilkanaCategoryStatusEnum
                ? $madre
                : SnkKilkanaCategoryStatusEnum::tryFrom((string) $madre);

            // Sin madre que mirar no se pasa: **falla cerrado**, como el resto de las puertas.
            // Un `parent_id` que apunta a una fila que no existe llega hasta aqui —la regla
            // `exists:` es del controlador, y el seeder y la consola no pasan por el—, y
            // `guardTree` tampoco lo caza: su consulta devuelve null igual que la de una raiz.
            if ($madre === null) {
                throw ValidationException::withMessages([
                    $campo => 'That parent section does not exist.',
                ]);
            }

            if ($suya > $madre->visibility()) {
                throw ValidationException::withMessages([
                    $campo => "A {$category->status->value} section cannot hang from a {$madre->value} one; launch the parent first.",
                ]);
            }
        }

        if (! $category->exists || ! $category->isDirty('status')) {
            return;
        }

        $masVisibles = self::query()
            ->where('parent_id', $category->id)
            ->get()
            ->filter(fn (self $hija) => $hija->status->visibility() > $suya)
            ->count();

        if ($masVisibles > 0) {
            throw ValidationException::withMessages([
                'status' => "This section has {$masVisibles} subsection(s) more visible than {$category->status->value}; change them first.",
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
