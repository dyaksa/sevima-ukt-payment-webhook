<?php

namespace App\Enums;

/**
 * Scenarios the mock gateway can play against the webhook endpoint.
 */
enum GatewayScenario: string
{
    case Success = 'success';
    case BadSignature = 'bad-signature';
    case Expiry = 'expiry';
    case Retry = 'retry';
    case WrongAmount = 'wrong-amount';
    case ExpiryVsSuccess = 'expiry-vs-success';
    case AuthorizationThenSuccess = 'authorization-then-success';
    case Failure = 'failure';
    case Refund = 'refund';
    case RefundFailed = 'refund-failed';
    case RefundBeforePayment = 'refund-before-payment';
}
