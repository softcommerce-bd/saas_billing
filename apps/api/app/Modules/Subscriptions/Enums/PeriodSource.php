<?php

namespace App\Modules\Subscriptions\Enums;

enum PeriodSource: string
{
    case Payment = 'payment';
    case Trial = 'trial';
    case Complimentary = 'complimentary';
}
