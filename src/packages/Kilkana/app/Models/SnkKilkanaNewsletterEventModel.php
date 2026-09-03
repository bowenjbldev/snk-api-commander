<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/** Lo que un suscriptor hizo con un número: una fila por hecho, y una sola vez. */
class SnkKilkanaNewsletterEventModel extends Model
{
    protected $table = 'kilkana_newsletter_events';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];

    public const OPEN = 'open';

    public const CLICK = 'click';

    public const UNSUB = 'unsub';

    /** Se le entregó: es lo que una tanda reintentada mira antes de mandar. No es una cifra del panel. */
    public const SENT = 'sent';

    /**
     * Apunta el hecho si no estaba, y dice si lo apuntó ahora: abrir veinte veces es una
     * apertura. `post_id` va a cero sin pieza, porque dos nulos no chocan en el índice único y
     * entonces dos aperturas en el mismo instante entrarían las dos; con cero, la segunda choca
     * y aquí se traga.
     */
    public static function record(int $issueId, int $subscriberId, string $kind, int $postId = 0): bool
    {
        try {
            $fila = self::query()->firstOrCreate(
                ['issue_id' => $issueId, 'subscriber_id' => $subscriberId, 'kind' => $kind, 'post_id' => $postId],
                ['created_at' => now()],
            );

            return $fila->wasRecentlyCreated;
        } catch (QueryException) {
            return false;
        }
    }
}
