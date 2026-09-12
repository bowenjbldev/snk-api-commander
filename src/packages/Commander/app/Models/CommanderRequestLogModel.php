<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log crudo por request (metering). Lo ESCRIBE CoreMeteringBufferSupport en lote, con un insert
 * de query builder sobre este modelo; el lado Commander —rollups, cuotas, dashboard y poda— lo
 * LEE también por él, así que tabla y conexión viven en un solo sitio.
 * El sello temporal es `created_at` (timestamps estándar); como el insert de query builder no
 * estampa, CoreMeteringMiddleware lo pone explícito en cada fila.
 */
class CommanderRequestLogModel extends Model
{
    protected $table = 'commander_request_logs';

    protected $connection = 'commander';

    protected $guarded = [];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(CommanderInstanceModel::class, 'instance_id');
    }
}
