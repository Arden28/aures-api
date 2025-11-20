<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING    = 'pending';
    case IN_PROGRESS = 'in_progress';
    case READY      = 'ready';
    case SERVED     = 'served';
    case COMPLETED  = 'completed';
    case CANCELLED  = 'cancelled';
}
