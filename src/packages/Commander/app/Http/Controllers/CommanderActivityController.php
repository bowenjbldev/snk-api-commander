<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Commander\Models\CommanderActivityEventModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

#[Group('Activity')]
class CommanderActivityController extends CoreBaseApiController
{
    protected string $modelClass = CommanderActivityEventModel::class;

    protected string $indexPermission = 'index activity';

    protected string $showPermission = 'show activity';

    protected array $allowedWith = ['instance.client', 'instance.application', 'subscription.account', 'subscription.application', 'invoice.account'];
}
