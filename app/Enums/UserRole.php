<?php

namespace App\Enums;

enum UserRole: string
{
    case OWNER   = 'owner';
    case MANAGER = 'manager';
    case WAITER  = 'waiter';
    case KITCHEN = 'kitchen';
    case CASHIER = 'cashier';
    case CLIENT  = 'client';
}