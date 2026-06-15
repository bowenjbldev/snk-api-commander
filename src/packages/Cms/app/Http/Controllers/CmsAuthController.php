<?php

namespace Packages\Cms\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreAuthController;

#[Group('Auth')]
class CmsAuthController extends CoreAuthController
{
}
