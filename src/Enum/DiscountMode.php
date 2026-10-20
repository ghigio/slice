<?php

namespace App\Enum;

enum DiscountMode: string
{
    case Proportional = 'proportional';
    case Equal = 'equal';
}
