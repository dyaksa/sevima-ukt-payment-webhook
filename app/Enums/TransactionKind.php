<?php

namespace App\Enums;

/**
 * The kind of money event a ledger row records, independent of the gateway's
 * wire names (see WebhookEvent::kind()).
 */
enum TransactionKind: string
{
    case Pending = 'pending';
    case Payment = 'payment';
    case FailedPayment = 'failed_payment';
    case Expiry = 'expiry';
    case Refund = 'refund';
    case FailedRefund = 'failed_refund';
}
