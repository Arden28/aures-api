<?php

namespace App\Enums;

enum OrderItemStatus: string
{
    case PENDING  = 'pending';
    case COOKING  = 'cooking';
    case READY    = 'ready';
    case SERVED   = 'served';
    case CANCELLED = 'cancelled';
}
