<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;

// Suscriptor del boletin. `status` es TODO el estado: no hay marca de tiempo de
// verificacion, porque `confirmed` ya dice que lo esta y dos representaciones del mismo
// hecho acaban desincronizadas.
class SnkKilkanaSubscriberModel extends Model
{
    protected $table = 'kilkana_subscribers';

    protected $guarded = [];
}
