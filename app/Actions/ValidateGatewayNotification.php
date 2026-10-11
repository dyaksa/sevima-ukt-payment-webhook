<?php

namespace App\Actions;

use App\Enums\WebhookEvent;
use App\Http\Requests\StoreWebhookRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

class ValidateGatewayNotification
{
    /**
     * Reuse the webhook's payload contract for the trusted, local gateway book.
     * This does not resolve the HTTP request or invoke signature middleware.
     *
     * @param  array<string, mixed>  $payload
     * @return array{event: WebhookEvent, idempotency_key: string, invoice_number: ?string, amount: ?int, occurred_at: CarbonImmutable, gateway_payment_id: ?string}
     */
    public function handle(array $payload): array
    {
        $request = StoreWebhookRequest::create('/', 'POST', content: json_encode($payload, JSON_THROW_ON_ERROR));
        $validator = Validator::make($payload, $request->rules(), $request->messages());
        $validator->validate();
        $request->setValidator($validator);

        return $request->notification();
    }
}
