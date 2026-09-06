<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;

/** El veredicto del gate sobre una URL. Qué significa cada columna lo dice la migración. */
class SnkKilkanaGateResultModel extends Model
{
    protected $table = 'kilkana_gate_results';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'passing' => 'array',
        'checked_at' => 'datetime',
    ];

    /** Las nueve invariantes, en el orden de las columnas del panel. */
    public const CHECKS = 9;

    /** Una ruta auditada que no pasa las nueve. Sin construir no falla: está pendiente. */
    public function failing(): bool
    {
        return $this->checked_at !== null && in_array(false, $this->passing ?? [], true);
    }

    /**
     * Cuántas fallan, para quien solo quiere la cifra. El veredicto se decide en PHP —`passing`
     * es un JSON de nueve booleanos—, así que hay que leer las filas; son una por ruta auditada.
     */
    public static function failingCount(): int
    {
        return self::query()->whereNotNull('checked_at')->get(['checked_at', 'passing'])
            ->filter(fn (self $r) => $r->failing())->count();
    }
}
