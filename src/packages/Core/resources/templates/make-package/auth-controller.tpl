<?php

namespace Packages\{{package}}\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreAuthController;

#[Group('Auth')]
class {{package}}AuthController extends CoreAuthController
{
}