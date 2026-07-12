<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;

// Slugs antiguos de un post: la URL pública vieja responde redirect al slug actual.
class SnkFolioPostSlugModel extends Model
{
    protected $table = 'folio_post_slugs';

    protected $guarded = [];
}
