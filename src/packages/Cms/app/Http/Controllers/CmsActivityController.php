<?php

namespace Packages\Cms\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Cms\Models\ActivityEventModel;

#[Group('Activity')]
class CmsActivityController extends CoreBaseApiController
{
    protected string $modelClass = ActivityEventModel::class;

    protected string $indexPermission = 'index activity';
    protected string $showPermission = 'show activity';
}
