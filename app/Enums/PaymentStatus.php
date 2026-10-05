<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PAID = 'paid';
    case PARTIAL = 'partial';
    case PENDING = 'pending';
    case OVERDUE = 'overdue';
}