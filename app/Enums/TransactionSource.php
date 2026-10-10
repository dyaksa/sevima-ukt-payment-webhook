<?php

namespace App\Enums;

enum TransactionSource: string
{
    case Webhook = 'webhook';
    case Reconciliation = 'reconciliation';
}
