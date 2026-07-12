<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;

class SnkFolioSubscriberModel extends Model
{
    protected $table = 'folio_subscribers';

    protected $guarded = [];

    protected $casts = ['verified_at' => 'datetime'];
}
