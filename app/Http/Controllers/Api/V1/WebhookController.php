<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ProcessWebhookNotification;
use App\Enums\WebhookOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebhookRequest;
use Illuminate\Http\JsonResponse;

class WebhookController extends Controller
{
    /**
     * Receive a gateway notification. Every 2xx stops the gateway's retries:
     * 200 for an applied or duplicate event, 202 for an unknown invoice.
     */
    public function store(StoreWebhookRequest $request, ProcessWebhookNotification $processWebhookNotification): JsonResponse
    {
        $outcome = $processWebhookNotification->handle($request->institution(), $request->notification(), $request->getContent());

        return response()->json(['outcome' => $outcome->value], match ($outcome) {
            WebhookOutcome::Accepted, WebhookOutcome::Duplicate => 200,
            WebhookOutcome::Unmatched => 202,
        });
    }
}
