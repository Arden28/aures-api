<?php

namespace App\Enums;

enum TableStatus: string
{
    case FREE           = 'free';
    case OCCUPIED       = 'occupied';
    case RESERVED       = 'reserved';
    case NEEDS_CLEANING = 'needs_cleaning';
}
