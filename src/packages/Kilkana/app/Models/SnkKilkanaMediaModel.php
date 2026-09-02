<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Packages\Kilkana\Enums\SnkKilkanaMediaRightsEnum;

class SnkKilkanaMediaModel extends Model
{
    use SoftDeletes;

    protected $table = 'kilkana_media';

    protected $guarded = [];

    // El hash no aporta nada al JSON: es solo el índice UNIQUE de dedup.
    protected $hidden = ['url_hash'];

    // usage_count viaja siempre en el JSON; el controlador hace withCount para no pagar N+1.
    protected $appends = ['usage_count'];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'integer',
        'size_bytes' => 'integer',
        'focal_x' => 'float',
        'focal_y' => 'float',
        // Como el punto, y por lo mismo: un `decimal` de MariaDB llega al JSON como cadena
        // —«0.0000»—, y al otro lado un `x * ancho` con una cadena da concatenacion, no
        // aritmetica. Las dos mitades del encuadre tienen que llegar del mismo tipo.
        'crop_x' => 'float',
        'crop_y' => 'float',
        'crop_w' => 'float',
        'crop_h' => 'float',
        'rights_expires_at' => 'date:Y-m-d',
        'rights_status' => SnkKilkanaMediaRightsEnum::class,
    ];

    protected static function booted(): void
    {
        // Quién lo tiró va con la fila: el borrado lógico solo escribe `deleted_at`.
        static::deleting(function (self $media) {
            if (! $media->isForceDeleting() && auth()->id() !== null) {
                $media->newQueryWithoutScopes()->whereKey($media->getKey())->update(['deleted_by' => auth()->id()]);
                $media->setAttribute('deleted_by', auth()->id());
            }
        });

        static::saving(function (self $media) {
            if ($media->isDirty('url') || $media->url_hash === null) {
                $media->url_hash = sha1($media->url);
            }
        });
    }

    /** Dedup por hash de la url (la url misma no cabe en un índice UNIQUE). */
    public static function firstOrCreateByUrl(string $url): self
    {
        return self::firstOrCreate(['url_hash' => sha1($url)], ['url' => $url]);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(SnkKilkanaMediaTagModel::class, 'kilkana_media_media_tag', 'media_id', 'media_tag_id');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(SnkKilkanaMediaCollectionModel::class, 'kilkana_media_collection_media', 'media_id', 'media_collection_id');
    }

    /** Posts que usan esta pieza como portada — base del usage_count y del filtro "sin usar". */
    /**
     * Las TRES formas de estar en uso. Aqui se declaran todas, y de aqui salen el contador y
     * la vista «sin usar»: si manana aparece una cuarta clave foranea hacia un medio, entra
     * en esta lista y las dos cosas se enteran solas.
     *
     * Se contaba solo la portada, y las otras dos existian igual. Consecuencia: la imagen
     * social de un articulo publicado y el retrato de una firma salian como «sin usar» con
     * el contador a cero — la biblioteca ofrecia para limpiar justo las fotos que si estaban
     * publicadas. Las FK son SET NULL, asi que borrarlas no rompia la base: hacia algo peor,
     * dejaba al articulo sin imagen social y al periodista sin cara, sin un solo error.
     */
    public const USOS = ['coveredPosts', 'socialPosts', 'avatarAuthors'];

    /** Posts que la usan de portada. */
    public function coveredPosts(): HasMany
    {
        return $this->hasMany(SnkKilkanaPostModel::class, 'cover_media_id');
    }

    /** Posts que la usan como imagen para redes. */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SnkKilkanaPostModel::class, 'og_image_id');
    }

    /** Firmas que la usan de retrato. */
    public function avatarAuthors(): HasMany
    {
        return $this->hasMany(SnkKilkanaAuthorModel::class, 'avatar_media_id');
    }

    /** Un medio al que no apunta nadie. Un solo sitio define «sin usar». */
    public function scopeUnused(Builder $query): Builder
    {
        foreach (self::USOS as $uso) {
            $query->whereDoesntHave($uso);
        }

        return $query;
    }

    /** Los `*_count` si el controlador los cargo (withCount); si no, se cuentan al vuelo. */
    public function getUsageCountAttribute(): int
    {
        $total = 0;
        foreach (self::USOS as $uso) {
            $columna = Str::snake($uso).'_count';
            $total += (int) ($this->attributes[$columna] ?? $this->{$uso}()->count());
        }

        return $total;
    }
}
