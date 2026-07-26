<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Packages\Snk\Kilkana\Enums\SnkKilkanaContentTierEnum;
use Packages\Snk\Kilkana\Enums\SnkKilkanaPostStatusEnum;

// Post normalizado: categoría/autor/portada por FK y tags por pivot. El JSON expone las
// relaciones anidadas (category, author, cover, tags); las FKs crudas y la relación interna
// coverMedia quedan ocultas.
class SnkKilkanaPostModel extends Model
{
    protected $table = 'kilkana_posts';

    protected $guarded = [];

    protected $with = ['category', 'author', 'coverMedia', 'tags'];

    // Una subconsulta por listado en vez de N contadores: el panel muestra cuántos
    // comentarios lleva cada artículo. Total, no solo aprobados: para moderar hay que ver
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

    protected static function booted(): void
    {
        static::saving(function (self $post) {
            // Sin slug no hay URL: se deriva del titular. `articulo` cubre el titular que no
            // deja slug latino (CJK) y el borrador sin título todavía.
            if (! $post->slug) {
                $post->slug = Str::slug((string) $post->title) ?: 'articulo';
            }

            // Se SELLA al publicar y solo la primera vez. Después, corregir `publish_at`
            // —para arreglar la hora de un cierre, por ejemplo— ya no mueve la URL.
            if ($post->status === SnkKilkanaPostStatusEnum::Published && ! $post->url_date) {
                // La cadena, no el atributo: el cast lo devuelve como Carbon en cuanto se lee.
                $date = ($post->publish_at ?? now())->toDateString();
                $post->url_date = $date;
                $post->slug = self::freeSlug($post->slug, $date, $post->id);
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
        $taken = fn (string $candidate) => self::query()
            ->where('url_date', $date)
            ->where('slug', $candidate)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if (! $taken($slug)) {
            return $slug;
        }
        for ($n = 2; $taken($slug.'-'.$n); $n++) {
        }

        return $slug.'-'.$n;
    }
}
