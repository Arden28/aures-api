<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH   = 'cash';
    case CARD   = 'card';
    case MOBILE = 'mobile';
    case OTHER  = 'other';
}
