<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Un movimiento del registro. Qué guarda cada columna lo dice la migración. */
class SnkKilkanaActivityModel extends Model
{
    protected $table = 'kilkana_activity';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];

    /** Lo que la máquina firma: el programador que publica a su hora. */
    public const SYSTEM = 'Scheduler';

    /** Cuánto registro se sirve. Lo anterior se queda, pero no se enseña. */
    public const DAYS = 90;

    /** Las tres pestañas del panel, por clase de movimiento. */
    public const TABS = [
        'content' => ['edit', 'review', 'publish', 'schedule', 'front', 'media', 'category', 'tag', 'delete', 'restore', 'newsletter'],
        'people' => ['comment', 'seat'],
        'system' => ['theme', 'settings', 'gate'],
    ];

    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->subDays(self::DAYS))->orderByDesc('created_at')->orderByDesc('id');
    }
}
