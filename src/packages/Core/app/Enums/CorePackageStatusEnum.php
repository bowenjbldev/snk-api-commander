<?php

namespace Packages\Core\Enums;

enum CorePackageStatusEnum: string
{
    case Active = 'active';
    case Grace = 'grace';
    case Suspended = 'suspended';
}
