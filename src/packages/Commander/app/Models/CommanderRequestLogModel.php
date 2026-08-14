<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log crudo por request (metering). Lo ESCRIBE CoreMeteringMiddleware por query builder
 * (Core no puede importar modelos de Commander); el lado Commander —rollups, cuotas,
 * dashboard y poda— lo LEE por este modelo, centralizando tabla + conexion en un sitio.
 * El sello temporal es `created_at` (timestamps estandar); como el writer inserta por query
 * builder, lo setea explicito (Eloquent no estampa en inserts de query builder).
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
