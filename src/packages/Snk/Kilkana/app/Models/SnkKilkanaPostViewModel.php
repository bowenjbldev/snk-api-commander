<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;

// Contador diario de lecturas de un post (UNIQUE post_id+date).
class SnkKilkanaPostViewModel extends Model
{
    protected $table = 'kilkana_post_views';

    protected $guarded = [];

    public $timestamps = false;

    /** +1 a la lectura de hoy sin castigar la fila del post. */
    public static function hit(int $postId): void
    {
        $today = now()->toDateString();
        $updated = self::query()->where('post_id', $postId)->where('date', $today)->increment('views');
        if ($updated === 0) {
            // Carrera benigna: si otro request creó la fila entre medias, incrementa.
            try {
                self::create(['post_id' => $postId, 'date' => $today, 'views' => 1]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                self::query()->where('post_id', $postId)->where('date', $today)->increment('views');
            }
        }
    }
}
