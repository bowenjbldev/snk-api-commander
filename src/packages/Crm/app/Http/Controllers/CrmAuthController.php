<?php

namespace Packages\Crm\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Packages\Core\Http\Controllers\CoreAuthController;

#[Group('Auth')]
class CrmAuthController extends CoreAuthController
{
}
