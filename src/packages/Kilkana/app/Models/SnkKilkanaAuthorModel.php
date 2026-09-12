<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaAuthorKindEnum;
use Packages\Kilkana\Models\Concerns\SnkKilkanaRetiredSlugsSupport;

// Firma editorial. La IDENTIDAD es la cuenta (`user_id` → `users.email`); aquí solo vive
// lo editorial: alias, slug público, bio, web, redes y foto.
class SnkKilkanaAuthorModel extends Model
{
    /**
     * La lista del catálogo: con lo que el panel pinta de cada firma, por slug.
     *
     * Es un scope y no el estado normal del modelo porque **solo lo quiere quien lista**: pedir
     * una firma para editarla no necesita contar sus piezas. Y vive aquí y no en el controlador
     * porque lo preguntan dos puertas —el recurso y el armazón del panel—.
     *
     * **`seat` es una subconsulta y no un accesor, y esa diferencia es lo que lo mantiene
     * dentro del panel.** Es el correo con el que se ENTRA, así que anexado al modelo viajaría
     * en cada firma que serializa cualquiera —y la portada pública y la página de cada artículo
     * anidan la firma entera—: el correo de acceso de la redacción saldría a la calle sin sesión.
     * Aquí solo existe para quien pide el catálogo, y ningún serializador futuro tiene que
     * acordarse de esconderlo.
     */
    public function scopeAsCatalogue(Builder $query): Builder
    {
        return $query
            ->addSelect(['seat' => CoreUserModel::query()
                ->select('email')
                ->whereColumn('users.id', 'kilkana_authors.user_id')
                ->limit(1),
            ])
            ->withCount('posts')
            ->withMax(['posts as last_published_at' => fn ($q) => $q->published()], 'publish_at')
            ->with(['retiredSlugs' => fn ($q) => $q->orderByDesc('created_at')])
            ->orderBy('slug');
    }

    use SnkKilkanaRetiredSlugsSupport;

    protected $table = 'kilkana_authors';

    protected $guarded = [];

    protected $casts = [
        'socials' => 'array',
        'noindex' => 'boolean',
        'kind' => SnkKilkanaAuthorKindEnum::class,
        // No es una columna: la calcula el `withMax` del listado. Se declara igual para que
        // salga en ISO como el resto de fechas de la API.
        'last_published_at' => 'datetime',
    ];

    /**
     * La cuenta va cargada porque el nombre publicado sale de ella cuando no hay alias.
     *
     * **Consecuencia:** cualquier proyección sobre firmas tiene que traer `user_id`. Sin la
     * clave no hay relación que cargar, y con el modo estricto encendido eso no devuelve null:
     * revienta. Es la misma regla que el resto de relaciones incluidas —quien pide una, pide su
     * llave—, solo que aquí la relación la pide el modelo y no quien consulta.
     */
    protected $with = ['user:id,name,lastname'];

    /**
     * Ni la relación ni la clave que la nombra.
     *
     * `user` va cargada y escondida porque el accesor del nombre la necesita. Y `user_id` se
     * esconde porque **dice quién tiene cuenta**: la portada pública anida la firma de cada
     * pieza, así que cualquiera podría separar las firmas con asiento de las que no —que es
     * justo la distinción que el panel reserva a quien administra cuentas— y de paso contar
     * cuántas cuentas hay y en qué orden entraron. No se puede quitar del `select`: sin la
     * clave no hay relación que cargar, y sin relación no hay nombre publicado.
     */
    protected $hidden = ['user', 'user_id'];

    protected $appends = ['name'];

    /**
     * El nombre que se publica: su alias si firma con seudónimo, y si no, el de su cuenta.
     *
     * Derivado, no guardado. Renombrar a la persona renombra su firma en el periódico
     * entero —tarjetas, ficha de autor, JSON-LD, feed— sin tocar nada más.
     */
    public function getNameAttribute(): string
    {
        $alias = trim((string) ($this->attributes['alias'] ?? ''));

        return $alias !== '' ? $alias : (string) ($this->user?->full_name ?? '');
    }

    /**
     * Las piezas firmadas.
     *
     * Existe para poder CONTARLAS sin traerlas: el panel enseña «Marta Beneyto · 34» y con una
     * relación declarada eso es un `withCount` en vez de once peticiones o el archivo entero en
     * el cliente.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(SnkKilkanaPostModel::class, 'author_id');
    }

    public function avatarMedia(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaMediaModel::class, 'avatar_media_id');
    }

    /** Las URLs que esta firma ha dejado atrás; el trait las mantiene. */
    public function retiredSlugs(): HasMany
    {
        return $this->hasMany(SnkKilkanaAuthorSlugModel::class, 'author_id');
    }

    public static function retiredSlugModel(): string
    {
        return SnkKilkanaAuthorSlugModel::class;
    }

    public static function retiredSlugOwnerKey(): string
    {
        return 'author_id';
    }

    /** La cuenta que identifica la firma. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(CoreUserModel::class, 'user_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $author) {
            // **Sin cuenta hace falta alias.** El nombre publicado sale del alias o de la
            // cuenta, así que una firma sin ninguno de los dos se publica como una cadena
            // vacía: una fila en la tabla del panel sin nombre y una página de autor titulada
            // «". "». Es la regla que sostiene que la cuenta pueda faltar.
            if ($author->user_id === null && trim((string) ($author->alias ?? '')) === '') {
                throw ValidationException::withMessages([
                    'alias' => 'A byline without an account needs an alias: it is the name that gets published.',
                ]);
            }
        });

        static::creating(function (self $author) {
            if ($author->slug) {
                return;
            }
            $author->slug = self::deriveSlug($author);
        });
    }

    /**
     * URL pública de una firma nueva, cuando nadie la ha escrito.
     *
     * Sale del nombre completo de la cuenta —o del alias, que es lo que firma— y **siempre
     * devuelve una**: dos periodistas pueden llamarse igual y `slug` es UNIQUE, así que la
     * segunda lleva cuatro cifras al azar, y un nombre sin letras latinas cae en `firma-NNNN`.
     *
     *     jorge-bowen-lopez  →  jorge-bowen-lopez-4821
     *
     * La URL con cifras no se sella: la ficha de la firma la deja cambiar y la anterior queda
     * redirigida (`SnkKilkanaRetiredSlugsSupport`). Es lo que permite que una cuenta que
     * puede escribir tenga firma sin que nadie tenga que intervenir.
     *
     * `getNameAttribute` no vale aquí: la relación no está cargada en una fila que aún no
     * existe, así que se pide la cuenta.
     */
    private static function deriveSlug(self $author): string
    {
        $alias = trim((string) ($author->attributes['alias'] ?? ''));
        $user = $author->user_id === null
            ? null
            : CoreUserModel::query()->whereKey($author->user_id)->first(['name', 'lastname']);

        $base = Str::slug($alias !== '' ? $alias : (string) ($user?->full_name ?? '')) ?: 'firma';

        if (! self::query()->where('slug', $base)->exists()) {
            return $base;
        }

        do {
            $slug = $base.'-'.random_int(1000, 9999);
        } while (self::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
