<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreAuthController;

#[Group('Auth')]
class CommanderAuthController extends CoreAuthController
{
}
