<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Snk\Kilkana\Enums\SnkKilkanaCommentStatusEnum;

class SnkKilkanaCommentModel extends Model
{
    protected $table = 'kilkana_comments';

    protected $guarded = [];

    /**
     * El correo de un lector anónimo no sale en ninguna respuesta.
     *
     * Se pide al comentar y no lo lee nadie —ni para avisar de una respuesta ni para
     * reconocer spam—, pero salía en el listado de moderación por el simple hecho de estar en
     * la tabla. Un dato personal que se enseña sin usarse no tiene defensa posible.
     */
    protected $hidden = ['author_email'];

    protected $casts = ['status' => SnkKilkanaCommentStatusEnum::class];

    /** Lo que ve un lector. Un solo sitio decide qué está publicado. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', SnkKilkanaCommentStatusEnum::Approved);
    }

    protected static function booted(): void
    {
        static::saving(function (self $comment) {
            if ($comment->parent_id === null) {
                return;
            }

            // UN nivel de respuestas, y aquí es donde se impone: `parent_id` admitiría por su
            // cuenta una cadena sin fin, y creamos doce niveles sin que nada lo impidiera.
            // Un hilo así no cabe indentado en ninguna pantalla, y borrar su raíz dispara una
            // cascada tan larga como la conversación.
            //
            // No es restricción de la base porque no puede serlo: MariaDB no admite
            // subconsultas en un `CHECK`, así que «tu padre no puede tener padre» solo cabe
            // aquí — y aquí pasan también el seeder y la consola.
            $abuelo = self::query()->whereKey($comment->parent_id)->value('parent_id');
            if ($abuelo !== null) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Replies go one level deep: answer the comment this one replies to.',
                ]);
            }
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaPostModel::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(CoreUserModel::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
