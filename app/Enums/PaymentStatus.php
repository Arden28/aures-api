<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case UNPAID   = 'unpaid';
    case PARTIAL  = 'partial';
    case PAID     = 'paid';
    case REFUNDED = 'refunded';
}
