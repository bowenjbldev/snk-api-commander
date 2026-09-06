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
     * dentro del panel.** Es el correo con el que se ENTRA, así que anexado al modelo viajaba
     * en cada firma que serializa cualquiera —y la portada pública y la página de cada artículo
     * anidan la firma entera—: el correo de acceso de la redacción salía a la calle sin sesión.
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
     * La cuenta viaja SIEMPRE **si la hay**, porque el nombre que se publica sale de ella.
     *
     * Con columnas: `users` guarda el correo del periodista y esto se sirve anidado en cada
     * post de la salida pública. `$hidden` la esconde del JSON —quien lee la firma quiere
     * `name`, no un objeto `user`— pero cargada, que es lo que necesita el accesor.
     */
    protected $with = ['user:id,name'];

    /**
     * Ni la relación ni la clave que la nombra.
     *
     * `user` va cargada y escondida porque el accesor del nombre la necesita. Y `user_id` se
     * esconde porque **decía quién tiene cuenta**: la portada pública anida la firma de cada
     * pieza, así que cualquiera podía separar las firmas con asiento de las que no —que es
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

        return $alias !== '' ? $alias : (string) ($this->user?->name ?? '');
    }

    /**
     * Las piezas firmadas.
     *
     * Existe para poder CONTARLAS sin traerlas: el panel enseña «Marta Beneyto · 34» y con una
     * relacion declarada eso es un `withCount` en vez de once peticiones o el archivo entero en
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
            // cuenta, asi que una firma sin ninguno de los dos se publica como una cadena
            // vacia: una fila en la tabla del panel sin nombre y una pagina de autor titulada
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
     * Dos periodistas pueden llamarse igual, y `slug` es UNIQUE: derivar a secas reventaba
     * el alta de la segunda cuenta con un 1062. La cascada usa lo que una redacción usa de
     * verdad —el segundo apellido, que ya vive en `users.full_name`— y **se para ahí**:
     *
     *     jorge-bowen  →  jorge-bowen-lopez  →  la elige una persona
     *
     * Sin `-2`. Un sufijo automático fija para siempre una URL que nadie eligió y que nadie
     * vuelve a mirar; Discord lo tuvo ocho años (`jorge#4821`) y lo retiró por eso mismo.
     * Cuando las dos candidatas están ocupadas —o el nombre no deja slug latino— esto lanza
     * un 422 sobre `slug`: el alta manual lo pinta como «esa URL ya está en uso» y la
     * automática (`SnkKilkanaUsersController::ensureByline`) lo convierte en un aviso.
     *
     * `getNameAttribute` no vale aquí: la relación no está cargada en una fila que aún no
     * existe, así que se pide la cuenta.
     */
    private static function deriveSlug(self $author): string
    {
        $alias = trim((string) ($author->attributes['alias'] ?? ''));
        $user = $author->user_id === null
            ? null
            : CoreUserModel::query()->whereKey($author->user_id)->first(['name', 'full_name']);

        // Con seudónimo no hay segunda vuelta: el nombre de su cuenta no es el que firma.
        $candidatas = $alias !== ''
            ? [Str::slug($alias)]
            : [Str::slug((string) ($user?->name ?? '')), Str::slug((string) ($user?->full_name ?? ''))];

        foreach (array_unique(array_filter($candidatas)) as $slug) {
            if (! self::query()->where('slug', $slug)->exists()) {
                return $slug;
            }
        }

        throw ValidationException::withMessages(['slug' => 'Byline URL already taken; choose one.']);
    }
}
