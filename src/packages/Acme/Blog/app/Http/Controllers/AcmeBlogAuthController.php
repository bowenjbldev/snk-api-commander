<?php

namespace Packages\Acme\Blog\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreAuthController;

#[Group('Auth')]
class AcmeBlogAuthController extends CoreAuthController
{
}
