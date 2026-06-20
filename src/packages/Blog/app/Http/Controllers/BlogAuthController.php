<?php

namespace Packages\Blog\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreAuthController;

#[Group('Auth')]
class BlogAuthController extends CoreAuthController
{
}
