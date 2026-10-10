<?php

namespace App\Actions;

use App\Enums\GatewayScenario;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Scopes\InstitutionScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SimulateGatewayNotification
{
    /**
     * Times Xendit delivers one notification at most: the first try plus its retries.
     */
    private const int XENDIT_DELIVERY_ATTEMPTS = 6;

    /**
     * Play a gateway scenario for one invoice: build a Xendit-shaped payload,
     * sign the exact bytes sent with the institution's secret from the
     * database, and post it to the webhook as a real HTTP request. The retry
     * scenario posts the identical request as often as Xendit retries.
     *
     * @return list<Response>
     *
     * @throws ModelNotFoundException when the institution or its invoice does not exist
     */
    public function handle(GatewayScenario $scenario, string $institutionCode, string $invoiceNumber, string $url): array
    {
        $institution = Institution::where('code', $institutionCode)->firstOrFail();
        Context::add(InstitutionScope::CONTEXT_KEY, $institution->id);
        $invoice = Invoice::where('invoice_number', $invoiceNumber)->firstOrFail();

        $payload = match ($scenario) {
            GatewayScenario::Success, GatewayScenario::BadSignature, GatewayScenario::Retry => $this->capturePayload($institution, $invoice),
            GatewayScenario::Expiry => $this->expiryPayload($institution, $invoice),
        };
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $secret = $scenario === GatewayScenario::BadSignature ? Str::random(64) : $institution->hmac_secret;
        $deliveries = $scenario === GatewayScenario::Retry ? self::XENDIT_DELIVERY_ATTEMPTS : 1;

        return array_map(fn (): Response => Http::timeout(10)
            ->withHeaders([
                'X-Institution-Code' => $institution->code,
                'X-Signature' => hash_hmac('sha256', $body, $secret),
            ])
            ->withBody($body)
            ->acceptJson()
            ->post($url), range(1, $deliveries));
    }

    /**
     * A payment request that expired unpaid, shaped like Xendit's payment_request.expiry.
     *
     * @return array<string, mixed>
     */
    private function expiryPayload(Institution $institution, Invoice $invoice): array
    {
        $now = now()->utc()->format('Y-m-d\TH:i:s.vp');

        return [
            'event' => 'payment_request.expiry',
            'business_id' => $institution->code,
            'created' => $now,
            'data' => [
                'payment_request_id' => 'pr-'.Str::uuid(),
                'reference_id' => $invoice->invoice_number,
                'currency' => 'IDR',
                'request_amount' => $invoice->amount,
                'status' => 'EXPIRED',
                'created' => $now,
                'updated' => $now,
            ],
        ];
    }

    /**
     * A capture of the full invoice amount, shaped like Xendit's payment.capture.
     *
     * @return array<string, mixed>
     */
    private function capturePayload(Institution $institution, Invoice $invoice): array
    {
        $now = now()->utc()->format('Y-m-d\TH:i:s.vp');

        return [
            'event' => 'payment.capture',
            'business_id' => $institution->code,
            'created' => $now,
            'data' => [
                'payment_id' => 'py-'.Str::uuid(),
                'payment_request_id' => 'pr-'.Str::uuid(),
                'reference_id' => $invoice->invoice_number,
                'currency' => 'IDR',
                'request_amount' => $invoice->amount,
                'status' => 'SUCCEEDED',
                'captures' => [[
                    'capture_id' => 'cptr-'.Str::uuid(),
                    'capture_amount' => $invoice->amount,
                    'capture_timestamp' => $now,
                ]],
                'created' => $now,
                'updated' => $now,
            ],
        ];
    }
}
