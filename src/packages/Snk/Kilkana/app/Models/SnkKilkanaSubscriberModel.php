<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;

class SnkKilkanaSubscriberModel extends Model
{
    protected $table = 'kilkana_subscribers';

    protected $guarded = [];

    protected $casts = ['verified_at' => 'datetime'];
}
