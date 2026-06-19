<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Log crudo por request (metering). Lo ESCRIBE CoreMeteringMiddleware por query builder
 * (Core no puede importar modelos de Commander); el lado Commander —rollups, cuotas,
 * dashboard y poda— lo LEE por este modelo, centralizando tabla + conexion en un sitio.
 * Sin timestamps de Eloquent: el sello temporal es `occurred_at`.
 */
class CommanderRequestLogModel extends Model
{
    protected $table = 'commander_request_logs';

    protected $connection = 'commander';

    public $timestamps = false;

    protected $guarded = [];
}
