<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// Contador diario de lecturas de un post (UNIQUE post_id+date).
class SnkKilkanaPostViewModel extends Model
{
    protected $table = 'kilkana_post_views';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * Los contadores de la fila diaria. Nada fuera de esta lista se escribe.
     *
     * `opens` y `engaged` son dos hechos distintos y el valor está en el cociente: quien abre y
     * se va a los dos segundos cuenta lo mismo que quien lee entero si solo hay un contador,
     * y entonces «lo más leído» premia al titular que mejor engaña. `readers` son visitantes
     * distintos del día: contra `engaged` dice si la pieza se relee.
     */
    public const COUNTERS = [
        'opens', 'engaged', 'readers',
        'ref_search', 'ref_social', 'ref_direct', 'ref_internal', 'ref_other',
    ];

    /**
     * Suma uno a los contadores indicados de la fila de hoy, en UNA sentencia.
     *
     * El `unique(post_id, date)` es lo que la hace posible: la base decide sola si toca crear
     * la fila del día o sumarle, de forma atómica, sin las dos o tres consultas de intentar
     * sumar, ver que no hay fila, crearla y capturar la violación de unicidad si otra petición
     * se adelanta. Aquí no hay carrera que manejar porque no hay hueco entre comprobar y
     * escribir.
     *
     * Va por `upsert` con expresiones crudas: el constructor no sabe sumar sobre el valor
     * existente por sí solo, y la expresión es lo que lo hace compilar tanto en MariaDB como
     * en el sqlite de la suite.
     *
     * Los nombres de columna se interpolan en esas expresiones, así que se filtran contra
     * `COUNTERS` antes: lo que no esté en la lista no llega a la consulta.
     */
    public static function record(int $postId, string ...$counters): void
    {
        $counters = array_values(array_unique(array_filter(
            $counters,
            fn (string $column) => in_array($column, self::COUNTERS, true),
        )));

        if ($counters === []) {
            return;
        }

        $increments = array_combine($counters, array_map(fn (string $c) => DB::raw("{$c} + 1"), $counters));

        self::query()->upsert(
            [['post_id' => $postId, 'date' => now()->toDateString()] + array_fill_keys($counters, 1)],
            ['post_id', 'date'],
            $increments,
        );
    }
}
