<?php

namespace Packages\Acme\Shop\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreAuthController;

#[Group('Auth')]
class AcmeShopAuthController extends CoreAuthController
{
}
