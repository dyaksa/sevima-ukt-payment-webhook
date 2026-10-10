<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

/**
 * A webhook whose institution code is unknown or whose signature does not
 * match. Both cases render the same 401, so codes cannot be probed. It is not
 * reported: the webhook log already records every rejection.
 */
class InvalidWebhookSignatureException extends Exception implements ShouldntReport
{
    /**
     * Render the exception as an HTTP response.
     */
    public function render(): JsonResponse
    {
        return response()->json(['message' => 'Invalid signature.'], 401);
    }
}
