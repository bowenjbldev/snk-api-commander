<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Packages\Kilkana\Enums\SnkKilkanaContentTierEnum;
use Packages\Kilkana\Enums\SnkKilkanaPostStatusEnum;

// Post normalizado: categoría/autor/portada por FK y tags por pivot. El JSON expone las
// relaciones anidadas (category, author, cover, tags); las FKs crudas y la relación interna
// coverMedia quedan ocultas.
class SnkKilkanaPostModel extends Model
{
    protected $table = 'kilkana_posts';

    protected $guarded = [];

    protected $with = ['category', 'author', 'coverMedia', 'tags'];

    // Una subconsulta por listado en vez de N contadores: la API incluye cuántos comentarios
    // lleva cada artículo. Total, no solo aprobados: para moderar hay que ver
    // que hay algo que moderar.
    protected $withCount = ['comments'];

    protected $hidden = ['category_id', 'author_id', 'cover_media_id', 'coverMedia'];

    protected $appends = ['cover', 'url_path'];

    protected function casts(): array
    {
        return [
            'body_blocks' => 'array',
            // Enum respaldado: sale al JSON como su cadena, así que el contrato de la API
            // no cambia — lo que cambia es que dentro ya no se compara con literales.
            'status' => SnkKilkanaPostStatusEnum::class,
            'featured' => 'boolean',
            'noindex' => 'boolean',
            'content_tier' => SnkKilkanaContentTierEnum::class,
            'publish_at' => 'datetime',
            // Fecha pelada, no timestamp: es un trozo de URL, no un instante.
            'url_date' => 'date:Y-m-d',
            'views_day' => 'integer',
            'views_week' => 'integer',
            'views_total' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaCategoryModel::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaAuthorModel::class, 'author_id');
    }

    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaMediaModel::class, 'cover_media_id');
    }

    /**
     * Imagen para redes, cuando la portada no sirve.
     *
     * NO va en `$with` a propósito, al revés que la portada: la portada se pinta en cada
     * tarjeta de cada listado y esta solo hace falta al montar la cabecera de UN artículo.
     * Cargarla siempre serían cincuenta consultas de más por listado para un dato que casi
     * nadie mira. Quien la necesite la pide.
     */
    public function ogImage(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaMediaModel::class, 'og_image_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(SnkKilkanaTagModel::class, 'kilkana_post_tag', 'post_id', 'tag_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(SnkKilkanaCommentModel::class, 'post_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(SnkKilkanaPostViewModel::class, 'post_id');
    }

    /**
     * Lo que ve un lector anónimo. Un solo sitio decide qué significa «publicado»: estaba
     * repetido en cada consulta pública y en el seeder, y una condición copiada seis veces
     * es una que algún día se queda corta en una sola de ellas.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', SnkKilkanaPostStatusEnum::Published);
    }

    /**
     * Lo que puede anunciarse a un buscador: publicado y sin `noindex`.
     *
     * Es un scope aparte de `published()` porque son preguntas distintas. Una pieza con
     * `noindex` SIGUE viéndose en el sitio —está publicada, se enlaza, se lee— y lo único
     * que cambia es que no se anuncia en el sitemap ni se pide que se indexe. Confundir las
     * dos la retiraría del periódico, que no es lo que se pidió.
     */
    public function scopeIndexable(Builder $query): Builder
    {
        return $query->published()->where('noindex', false);
    }

    /**
     * Cuerpo que puede salir por la API pública.
     *
     * Mientras no exista una sesión lectora ligada a una suscripción, `metered` y `locked`
     * fallan cerrados: solo exponen el teaser hasta el primer muro. La API autenticada sigue
     * serializando el cuerpo entero. Si falta el bloque, se entrega únicamente
     * un muro automático; nunca todo el texto por un error editorial.
     */
    public function publicBodyBlocks(): array
    {
        $blocks = $this->body_blocks ?? [];
        if ($this->content_tier === SnkKilkanaContentTierEnum::Free) {
            return $blocks;
        }

        foreach ($blocks as $index => $block) {
            if (($block['type'] ?? null) === 'paywall') {
                return array_slice($blocks, 0, $index + 1);
            }
        }

        return [['id' => 'paywall-auto', 'type' => 'paywall', 'config' => []]];
    }

    /** La portada serializada como `cover` (objeto media o null). */
    public function getCoverAttribute(): ?SnkKilkanaMediaModel
    {
        return $this->coverMedia;
    }

    /**
     * Ruta pública de la pieza: `2027/05/09/el-barca-gana-en-el-bernabeu`.
     *
     * La compone el modelo y no cada consumidor: es la ÚNICA definición de cómo se
     * direcciona un artículo, y con la fecha repartida en dos columnas cualquier otro sitio
     * que la recomponga acabaría discrepando. Relativa a propósito —sin `/article` ni
     * locale—: el prefijo lo pone quien sirve HTML, que es el único que sabe en qué idioma.
     *
     * Null mientras no esté publicada: un borrador no tiene URL pública.
     */
    public function getUrlPathAttribute(): ?string
    {
        $date = $this->url_date;

        return $date && $this->slug ? $date->format('Y/m/d').'/'.$this->slug : null;
    }

    /**
     * Tope del slug, y de aquí sale también el ancho de la columna.
     *
     * 200 es lo que usa WordPress en `post_name`, y deja sitio de sobra en
     * `kilkana_post_slugs.path`, que guarda la ruta ENTERA: 200 + los 11 de la fecha = 211,
     * lejos de sus 255.
     *
     * Antes no había tope y la columna eran 255. Como `title` valida hasta 500, un titular
     * largo derivaba un slug que no cabía y el alta moría con un `1406 Data too long` —y
     * renombrar un artículo de slug largo hacía lo mismo sobre `path`, dejando la redirección
     * sin grabar—. El tope al derivar es lo que cierra las dos.
     */
    public const SLUG_MAX = 200;

    /** Recorta sin dejar el guion colgando, que daría `mi-titular-` de URL. */
    private static function capSlug(string $slug, int $max = self::SLUG_MAX): string
    {
        return rtrim(mb_substr($slug, 0, $max), '-');
    }

    /**
     * Una ruta está ocupada si la tiene otro artículo **o si la conserva el historial de otro**.
     *
     * La segunda mitad es la que faltaba, y es la que importa: una URL retirada sigue
     * redirigiendo, así que no está libre. Sin esta comprobación, un artículo podía adoptar la
     * URL que otro acababa de dejar y el guardado **borraba la redirección del primero** —sus
     * lectores pasaban a aterrizar en la pieza equivocada, con un 200 y sin aviso—.
     *
     * `$ignoreId` deja fuera al propio artículo, para que revertir un renombrado siga siendo
     * posible: su propia URL vieja no puede bloquearle.
     */
    public static function pathTaken(string $date, string $slug, ?int $ignoreId = null): bool
    {
        $enArticulos = self::query()
            ->where('url_date', $date)
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($enArticulos) {
            return true;
        }

        return SnkKilkanaPostSlugModel::query()
            ->where('path', str_replace('-', '/', $date).'/'.$slug)
            ->when($ignoreId, fn (Builder $query) => $query->where('post_id', '!=', $ignoreId))
            ->exists();
    }

    protected static function booted(): void
    {
        static::saving(function (self $post) {
            // Sin slug no hay URL: se deriva del titular. `articulo` cubre el titular que no
            // deja slug latino (CJK) y el borrador sin título todavía.
            if (! $post->slug) {
                $post->slug = self::capSlug(Str::slug((string) $post->title)) ?: 'articulo';
            } else {
                // Quien no pasa por la validación de la API —seeder, consola— también entra
                // aquí: mejor recortado que un 500.
                $post->slug = self::capSlug((string) $post->slug);
            }

            // No sale a la calle una pieza cuya mesa todavía no existe para el lector.
            //
            // Sin esto, publicar en una sección en preparación dejaba el artículo en portada
            // con un kicker enlazando a una página que no se puede abrir. Se comprueba solo
            // cuando cambia algo que importa —el estado o la sección—, no en cada guardado.
            if ($post->status->requiresPublishPermission()
                && ($post->isDirty('status') || $post->isDirty('category_id'))) {
                $seccionViva = SnkKilkanaCategoryModel::query()
                    ->whereKey($post->category_id)
                    ->live()
                    ->exists();

                if (! $seccionViva) {
                    throw ValidationException::withMessages([
                        'category' => 'That section has not been launched yet; launch it or pick another.',
                    ]);
                }
            }

            // Se SELLA al publicar y solo la primera vez. Después, corregir `publish_at`
            // —para arreglar la hora de un cierre, por ejemplo— ya no mueve la URL.
            $sellado = false;
            if ($post->status === SnkKilkanaPostStatusEnum::Published && ! $post->url_date) {
                // La cadena, no el atributo: el cast lo devuelve como Carbon en cuanto se lee.
                $date = ($post->publish_at ?? now())->toDateString();
                $post->url_date = $date;
                $post->slug = self::freeSlug($post->slug, $date, $post->id);
                $sellado = true;
            }

            // Mover la URL de una pieza YA sellada es un acto deliberado, y se comprueba.
            // Antes no lo comprobaba nadie: hacia un slug ocupado reventaba con un 1062 —un
            // 500 con el SQL dentro— y hacia uno que estuviera en el historial de otro
            // artículo borraba su redirección. Aquí se para con un 422 que se explica, igual
            // que al elegir a mano la URL de una firma o de una etiqueta.
            if (! $sellado && $post->url_date && ($post->isDirty('slug') || $post->isDirty('url_date'))) {
                $date = $post->url_date instanceof \DateTimeInterface
                    ? $post->url_date->format('Y-m-d')
                    : (string) $post->url_date;

                if (self::pathTaken($date, (string) $post->slug, $post->id)) {
                    throw ValidationException::withMessages([
                        'slug' => 'That article URL is already in use, now or by a redirect; choose another.',
                    ]);
                }
            }
        });
    }

    /**
     * Slug libre DENTRO DE SU DÍA. Solo desempata a dos piezas del mismo día con el mismo
     * titular —un duplicado real—, porque el titular que vuelve cada temporada ya cae en otra
     * fecha y no colisiona. Por eso aquí sí vale un número: no hay nada que el número tape.
     */
    private static function freeSlug(string $slug, string $date, ?int $ignoreId): string
    {
        // Cuenta también el historial: una URL retirada sigue apuntando a su artículo, así que
        // una pieza nueva no puede nacer encima de ella.
        $taken = fn (string $candidate) => self::pathTaken($date, $candidate, $ignoreId);

        if (! $taken($slug)) {
            return $slug;
        }

        // El sufijo también tiene que caber: se recorta la base para dejarle sitio, o un slug
        // que llegue justo al tope se saldría de la columna al desempatarlo.
        $base = self::capSlug($slug, self::SLUG_MAX - 4);
        for ($n = 2; $taken($base.'-'.$n); $n++) {
        }

        return $base.'-'.$n;
    }
}
