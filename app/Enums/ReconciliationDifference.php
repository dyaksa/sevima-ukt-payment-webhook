<?php

namespace App\Enums;

enum ReconciliationDifference: string
{
    case MissingWebhook = 'missing_webhook';
    case MissingGatewayEvent = 'missing_gateway_event';
    case AmountMismatch = 'amount_mismatch';
}
