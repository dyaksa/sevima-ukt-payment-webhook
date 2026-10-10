<?php

namespace App\Enums;

/**
 * What happened to one received notification, as stored in the webhook log.
 */
enum WebhookOutcome: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Unmatched = 'unmatched';
    case Unauthorized = 'unauthorized';
    case Invalid = 'invalid';
    case Error = 'error';
}
