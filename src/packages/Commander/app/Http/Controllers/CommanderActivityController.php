<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Commander\Models\CommanderActivityEventModel;

#[Group('Activity')]
class CommanderActivityController extends CoreBaseApiController
{
    protected string $modelClass = CommanderActivityEventModel::class;

    protected string $indexPermission = 'index activity';
    protected string $showPermission = 'show activity';
}
