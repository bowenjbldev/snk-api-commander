<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Packages\Kilkana\Enums\SnkKilkanaContentTierEnum;

/**
 * Una lectura medida: la cuenta, la pieza y el mes. Qué consume y qué no lo dice la migración.
 */
class SnkKilkanaReaderReadModel extends Model
{
    protected $table = 'kilkana_reader_reads';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'period' => 'date',
        'read_at' => 'datetime',
    ];

    /**
     * Las lecturas de un mes. Sin argumento, las del mes en curso.
     *
     * Se compara con la fecha entera y no con `Y-m-d`: la columna es `date`, pero el cast la
     * escribe con hora, y SQLite compara cadenas tal cual.
     */
    public function scopeInMonth(Builder $query, ?Carbon $when = null): Builder
    {
        return $query->where('period', ($when ?? now())->copy()->startOfMonth());
    }

    /**
     * Apunta la lectura de una pieza por una cuenta, una vez por mes: la segunda vez no
     * escribe nada. Solo si la pieza es medida; lo demás no consume.
     */
    public static function record(int $userId, SnkKilkanaPostModel $post, ?Carbon $when = null): void
    {
        if ($post->content_tier !== SnkKilkanaContentTierEnum::Metered) {
            return;
        }
        $when ??= now();

        self::firstOrCreate([
            'user_id' => $userId,
            'post_id' => $post->getKey(),
            'period' => $when->copy()->startOfMonth(),
        ], ['read_at' => $when]);
    }
}
