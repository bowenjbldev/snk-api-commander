<?php

namespace Packages\Core\Enums;

enum CoreServiceStatusEnum: string
{
    case Active = 'active';
    case Grace = 'grace';
    case Suspended = 'suspended';
}
