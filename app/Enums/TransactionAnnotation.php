<?php

namespace App\Enums;

enum TransactionAnnotation: string
{
    case AmountMismatch = 'amount_mismatch';
    case DuplicatePayment = 'duplicate_payment';
}
