<?php

namespace Packages\Shop\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreAuthController;

#[Group('Auth')]
class ShopAuthController extends CoreAuthController
{
}
