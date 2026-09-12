<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;

// Slugs retirados de un post: la URL pública que dejó atrás redirige al slug actual.
class SnkKilkanaPostSlugModel extends Model
{
    protected $table = 'kilkana_post_slugs';

    protected $guarded = [];
}
