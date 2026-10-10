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
}
