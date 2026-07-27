<?php

namespace Packages\Snk\Kilkana\Models;

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
     * Va en SQL directo a propósito: `ON DUPLICATE KEY UPDATE` no tiene equivalente en el
     * constructor de consultas que sepa sumar sobre el valor existente. Ata a MySQL/MariaDB,
     * donde este paquete ya está atado por los ENUM y el indice FULLTEXT.
     *
     * Los nombres de columna se interpolan, así que se filtran contra `COUNTERS` antes: lo
     * que no esté en la lista no llega a la consulta. `date` va entre comillas, es reservada.
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

        $tabla = (new self)->getTable();
        $columnas = implode(', ', $contadores);
        $unos = implode(', ', array_fill(0, count($contadores), '1'));
        $sumas = implode(', ', array_map(fn (string $c) => "{$c} = {$c} + 1", $contadores));

        DB::statement(
            "INSERT INTO {$tabla} (post_id, `date`, {$columnas}) VALUES (?, ?, {$unos})
             ON DUPLICATE KEY UPDATE {$sumas}",
            [$postId, now()->toDateString()],
        );
    }
}
