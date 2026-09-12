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

    /*
     * La madre **no se carga sola**: en `$with` arrastraría una consulta y una relación
     * escondida a cada sitio donde aparece una sección, también anidada dentro de cada pieza
     * de un listado, que es donde nadie la pinta. `parent_slug` y `parent_name` los declara
     * `SnkKilkanaCategoryResource`, y salen cuando quien consulta incluye la madre.
     *
     * Sale como SLUG y no como objeto anidado: el cliente pregunta «¿este artículo está en
     * Sports?» con un slug, y un `parent_id` numérico no se puede casar contra eso sin tener el
     * catálogo entero delante, algo que el render SSR de la portada no tiene. Con `parent_slug`,
     * pertenecer a una sección es una comparación de cadenas sobre el propio post.
     */

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
     * alta sale sin `status` ni `noindex`, y quien la pinta no sabe si la sección acaba de
     * nacer en preparación o si el campo no existe.
     */
    protected $attributes = [
        'status' => SnkKilkanaCategoryStatusEnum::Draft->value,
        'noindex' => false,
    ];

    /**
     * Las que salen en la navegación. Un solo sitio define «sección del menú».
     *
     * No es lo mismo que existir para el lector: una `hidden` se abre por su URL y no
     * aparece aquí, que es exactamente para lo que existe ese estado. Quien pregunte por lo
     * segundo usa `reachable()`, y confundirlos publica en el menú lo que se retiró de él.
     */
    public function scopeInMenu(Builder $query): Builder
    {
        return $query->whereIn('status', self::statuses(fn ($status) => $status->inMenu()));
    }

    /**
     * La lista del catálogo: con lo que el panel pinta de cada sección y en el orden del menú.
     *
     * Es un scope y no el estado normal del modelo porque **solo lo quiere quien lista**: pedir
     * una sección para editarla no necesita contar sus piezas. Y vive aquí y no en el
     * controlador porque lo preguntan dos puertas —el recurso y el armazón del panel—, y una
     * lista que se arma dos veces acaba enseñando columnas distintas en cada una.
     *
     * El orden es `position` y no la clave: el menú del periódico es esta lista, y ordenar por
     * id devolvería el orden de creación en cuanto alguien reordene. `id` desempata, porque
     * `position` nace a cero en todas y sin segundo criterio el orden sería el que quisiera la
     * base.
     */
    public function scopeAsCatalogue(Builder $query): Builder
    {
        return $query
            ->withCount('posts')
            ->withMax(['posts as last_published_at' => fn ($q) => $q->published()], 'publish_at')
            ->with(['retiredSlugs' => fn ($q) => $q->orderByDesc('created_at')])
            ->orderBy('position')
            ->orderBy('id');
    }

    /** Las que el lector puede abrir por su URL: las del menú y las retiradas de él. */
    public function scopeReachable(Builder $query): Builder
    {
        return $query->whereIn('status', self::statuses(fn ($status) => $status->isPublic()));
    }

    /**
     * Los estados que contestan que sí a una pregunta del enum.
     *
     * Los scopes preguntan y no enumeran a propósito: con la lista escrita aquí, un estado
     * nuevo entra en el enum y estos dos siguen contestando lo de ayer sin que nada falle.
     *
     * @param  callable(SnkKilkanaCategoryStatusEnum): bool  $filter
     * @return list<string>
     */
    private static function statuses(callable $filter): array
    {
        return array_values(array_map(
            fn (SnkKilkanaCategoryStatusEnum $status) => $status->value,
            array_filter(SnkKilkanaCategoryStatusEnum::cases(), $filter)
        ));
    }

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
     * Las piezas archivadas en esta sección.
     *
     * Existe para poder CONTARLAS sin traerlas: el panel enseña «Local 40» y con una
     * relación declarada eso es un `withCount` —una subconsulta— en vez de diecisiete
     * peticiones o un listado entero en el cliente. No se carga nunca entera: una sección de
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
     * así que sin tope un nombre de 85 caracteres tumbaría el alta con un `1406 Data too long`.
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
            // Sin esto, crear una sección con un nombre que ya existe devuelve el 1062 con el
            // nombre del índice dentro en vez de decir qué pasa.
            if (! $category->slug || self::slugTaken($category->slug)) {
                throw ValidationException::withMessages([
                    'slug' => 'That section URL is already in use, now or by a redirect; choose another.',
                ]);
            }
        });

        // Al crear, el slug se deriva más arriba en este mismo evento: `saving` dispara antes y
        // aún lo vería vacío.
        static::creating(fn (self $category) => self::guardReservedSlug($category));
        static::updating(fn (self $category) => self::guardReservedSlug($category));

        static::saving(function (self $category) {
            self::guardTree($category);
            self::guardStatus($category);
            self::guardVisibility($category);
        });
    }

    /**
     * Los primeros segmentos que la calle tiene fijos: una sección con ese slug se guarda y su
     * página pública no existe nunca, sin que nada avise. La calle está en `kilkana.app`
     * (`src/routes`), así que esta lista se mueve con la suya.
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
     * No se devuelve a preparación una sección que ya tiene piezas publicadas.
     *
     * Es la otra mitad del muro que impide publicar en una sección sin lanzar: sin esto se
     * llega al mismo sitio por la puerta de atrás —lanzar la mesa, publicar en ella, y
     * después retirarla— y esas piezas quedan colgando de una sección que el lector no puede
     * abrir, con su kicker enlazando a una página que no existe.
     *
     * Retirarla del menú sí que se puede con el archivo lleno: `hidden` deja las piezas
     * abriéndose por su URL, que es justo lo que `draft` no hace.
     *
     * Cuenta también lo que cuelga de sus subsecciones: el árbol tiene dos niveles y la madre
     * de una sección viva no puede estar en preparación.
     */
    private static function guardStatus(self $category): void
    {
        if (! $category->exists || ! $category->isDirty('status')) {
            return;
        }
        if ($category->status->isPublic()) {
            return;
        }

        // Las suyas Y las de sus subsecciones. Contando solo las propias, una madre sin piezas
        // y con una hija publicando pasaría a preparación: la hija seguiría alcanzable, sus
        // piezas públicas, y la página de la madre respondería 404.
        $published = SnkKilkanaPostModel::query()
            ->whereIn('category_id', [
                $category->id,
                ...self::query()->where('parent_id', $category->id)->pluck('id')->all(),
            ])
            ->published()
            ->count();

        if ($published > 0) {
            throw ValidationException::withMessages([
                'status' => "This section has {$published} published article(s); move or unpublish them first.",
            ]);
        }
    }

    /**
     * Una sección no se ve más que su madre, y esto cierra las dos puertas.
     *
     * `guardStatus` cierra la del estado —no devuelvas a preparación lo que tiene publicados—
     * y esto, la de la jerarquía: sin ella, mover una sección viva con archivo bajo una madre
     * en preparación pasa limpio, y se llega al mismo sitio prohibido. La forma la comprueba
     * `guardTree`; el estado, esto.
     *
     * Y no es solo `draft`: una hija `live` bajo una madre `hidden` tampoco está en el menú de
     * nadie, porque el suyo cuelga de un desplegable que no se pinta. Por eso se compara la
     * escala del enum y no un booleano.
     *
     * Se mira en las dos direcciones porque las dos escrituras llevan al mismo estado
     * imposible: bajar el estado de una madre con hijas más visibles, y colgar una hija de una
     * madre menos visible.
     */
    private static function guardVisibility(self $category): void
    {
        if (! $category->isDirty(['status', 'parent_id'])) {
            return;
        }

        $ownVisibility = $category->status->visibility();

        if ($category->parent_id !== null) {
            $field = $category->isDirty('parent_id') ? 'parent_id' : 'status';
            $parent = self::query()->whereKey($category->parent_id)->value('status');
            $parent = $parent instanceof SnkKilkanaCategoryStatusEnum
                ? $parent
                : SnkKilkanaCategoryStatusEnum::tryFrom((string) $parent);

            // Sin madre que mirar no se pasa: **falla cerrado**, como el resto de las puertas.
            // Un `parent_id` que apunta a una fila que no existe llega hasta aquí —la regla
            // `exists:` es del controlador, y el seeder y la consola no pasan por él—, y
            // `guardTree` tampoco lo caza: su consulta devuelve null igual que la de una raíz.
            if ($parent === null) {
                throw ValidationException::withMessages([
                    $field => 'That parent section does not exist.',
                ]);
            }

            if ($ownVisibility > $parent->visibility()) {
                throw ValidationException::withMessages([
                    $field => "A {$category->status->value} section cannot hang from a {$parent->value} one; launch the parent first.",
                ]);
            }
        }

        if (! $category->exists || ! $category->isDirty('status')) {
            return;
        }

        $moreVisible = self::query()
            ->where('parent_id', $category->id)
            ->get()
            ->filter(fn (self $child) => $child->status->visibility() > $ownVisibility)
            ->count();

        if ($moreVisible > 0) {
            throw ValidationException::withMessages([
                'status' => "This section has {$moreVisible} subsection(s) more visible than {$category->status->value}; change them first.",
            ]);
        }
    }

    /**
     * El árbol tiene DOS niveles y no admite ciclos, y aquí es donde se impone.
     *
     * No por comodidad: MariaDB no acepta subconsultas en un `CHECK`, así que «tu madre no
     * puede tener madre» no se puede escribir como restricción de la base. O vive en el
     * modelo o no vive en ninguna parte, y sin ella:
     *
     *   - Cabe una nieta, y no falla: la consulta de sección mira la categoría y su madre,
     *     así que los artículos del tercer nivel DESAPARECEN del listado de la abuela sin un
     *     solo error.
     *   - Cabe un ciclo, cerrado con dos PUT que devuelven 200 cada uno.
     *
     * La validación del controlador no basta: el seeder y la consola no pasan por ella, y
     * por eso la comprobación va en el modelo.
     */
    private static function guardTree(self $category): void
    {
        if ($category->parent_id === null) {
            return; // una raíz no tiene nada que comprobar
        }

        $error = fn (string $message) => throw ValidationException::withMessages(['parent_id' => $message]);

        if ($category->exists && (int) $category->parent_id === (int) $category->id) {
            $error('A section cannot be its own parent.');
        }

        // Dos niveles: tu madre tiene que ser una raíz. Con esta sola regla los ciclos
        // tampoco caben —cerrar uno exigiría que las dos tuvieran madre— pero el id se
        // comprueba aparte, porque una fila que se apunta a sí misma leería de la base su
        // propio `parent_id` guardado y se colaría.
        if (self::query()->whereKey($category->parent_id)->value('parent_id') !== null) {
            $error('A subsection cannot hang from another subsection: the tree has two levels.');
        }

        // Y en el otro sentido: si ya eres madre, no puedes colgar de nadie. Sin esto, mover
        // una sección con hijas bajo otra crea el tercer nivel por la puerta de atrás.
        if ($category->exists && self::query()->where('parent_id', $category->id)->exists()) {
            $error('A section with subsections cannot become a subsection itself.');
        }
    }
}
