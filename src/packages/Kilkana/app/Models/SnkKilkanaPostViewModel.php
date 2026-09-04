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
     * `opens` y `reads` son dos hechos distintos y el valor está en el cociente: quien abre y
     * se va a los dos segundos cuenta lo mismo que quien lee entero si solo hay un contador,
     * y entonces «lo más leído» premia al titular que mejor engaña. `readers` son visitantes
     * distintos del día: contra `reads` dice si la pieza se relee.
     */
    public const COUNTERS = [
        'opens', 'engaged', 'readers',
        'ref_search', 'ref_social', 'ref_direct', 'ref_internal', 'ref_other',
    ];

    /**
     * Suma uno a los contadores indicados de la fila de hoy, en UNA sentencia.
     *
     * El `unique(post_id, date)` es lo que la hace posible: la base decide sola si toca crear
     * la fila del día o sumarle, de forma atómica. Antes eran dos o tres consultas —intentar
     * sumar, ver que no había fila, crearla, y capturar la violación de unicidad si otra
     * petición se había adelantado— para acabar en el mismo sitio. Aquí no hay carrera que
     * manejar porque no hay hueco entre comprobar y escribir.
     *
     * Va por `upsert` con expresiones crudas: el constructor no sabe sumar sobre el valor
     * existente por si solo, y la expresion es lo que lo hace compilar tanto en MariaDB como
     * en el sqlite de la suite.
     *
     * Los nombres de columna se interpolan en esas expresiones, así que se filtran contra
     * `COUNTERS` antes: lo que no esté en la lista no llega a la consulta.
     */
    public static function record(int $postId, string ...$contadores): void
    {
        $contadores = array_values(array_unique(array_filter(
            $contadores,
            fn (string $columna) => in_array($columna, self::COUNTERS, true),
        )));

        if ($contadores === []) {
            return;
        }

        // `upsert` compila la suma sobre el valor existente en MariaDB y en sqlite, que es
        // donde corre la suite; las columnas vienen filtradas contra `COUNTERS`, asi que la
        // expresion cruda solo lleva nombres de la lista.
        $sumas = array_combine($contadores, array_map(fn (string $c) => DB::raw("{$c} + 1"), $contadores));

        self::query()->upsert(
            [['post_id' => $postId, 'date' => now()->toDateString()] + array_fill_keys($contadores, 1)],
            ['post_id', 'date'],
            $sumas,
        );
    }
}
