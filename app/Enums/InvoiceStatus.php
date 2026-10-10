<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Expired = 'expired';
    case Refunded = 'refunded';
}
