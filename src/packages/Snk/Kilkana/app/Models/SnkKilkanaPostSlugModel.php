<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;

// Slugs antiguos de un post: la URL pública vieja responde redirect al slug actual.
class SnkKilkanaPostSlugModel extends Model
{
    protected $table = 'kilkana_post_slugs';

    protected $guarded = [];
}
