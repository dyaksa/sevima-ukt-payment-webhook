<?php

namespace App\Actions;

use App\Enums\GatewayScenario;
use App\GatewayBook;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Scopes\InstitutionScope;
use Carbon\CarbonImmutable;
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

    public function __construct(
        private GatewayBook $gatewayBook,
        private ValidateGatewayNotification $validateGatewayNotification,
    ) {}

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
    public function handle(GatewayScenario $scenario, string $institutionCode, string $invoiceNumber, string $url, ?CarbonImmutable $occurredAt = null): array
    {
        $institution = Institution::where('code', $institutionCode)->firstOrFail();
        Context::add(InstitutionScope::CONTEXT_KEY, $institution->id);
        $invoice = Invoice::where('invoice_number', $invoiceNumber)->firstOrFail();

        $occurredAt ??= CarbonImmutable::now();
        $payloads = match ($scenario) {
            GatewayScenario::Success, GatewayScenario::BadSignature, GatewayScenario::Lost => [$this->capturePayload($institution, $invoice, $occurredAt)],
            GatewayScenario::Retry => array_fill(0, self::XENDIT_DELIVERY_ATTEMPTS, $this->capturePayload($institution, $invoice, $occurredAt)),
            GatewayScenario::WrongAmount => [$this->capturePayload($institution, $invoice, $occurredAt, $invoice->amount === 1 ? 2 : $invoice->amount - 1)],
            GatewayScenario::Expiry => [$this->expiryPayload($institution, $invoice, $occurredAt)],
            GatewayScenario::ExpiryVsSuccess => [
                $this->capturePayload($institution, $invoice, $occurredAt),
                $this->expiryPayload($institution, $invoice, $occurredAt->subSecond()),
            ],
            GatewayScenario::AuthorizationThenSuccess => $this->authorizationPayloads($institution, $invoice, $occurredAt),
            GatewayScenario::Failure => [$this->failurePayload($institution, $invoice, $occurredAt)],
            GatewayScenario::Refund, GatewayScenario::RefundFailed, GatewayScenario::RefundBeforePayment => $this->refundPayloads($institution, $invoice, $occurredAt, $scenario),
        };
        $events = array_values(array_unique($payloads, SORT_REGULAR));

        foreach ($events as $payload) {
            $this->validateGatewayNotification->handle($payload);
        }

        $this->gatewayBook->append($institution->code, $events);

        if ($scenario === GatewayScenario::Lost) {
            return [];
        }

        $secret = $scenario === GatewayScenario::BadSignature ? Str::random(64) : $institution->hmac_secret;

        return array_map(function (array $payload) use ($institution, $secret, $url): Response {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);

            return Http::timeout(10)
                ->withHeaders([
                    'X-Institution-Code' => $institution->code,
                    'X-Signature' => hash_hmac('sha256', $body, $secret),
                ])
                ->withBody($body)
                ->acceptJson()
                ->post($url);
        }, $payloads);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function authorizationPayloads(Institution $institution, Invoice $invoice, CarbonImmutable $occurredAt): array
    {
        $capture = $this->capturePayload($institution, $invoice, $occurredAt);
        $authorization = $capture;
        $authorization['event'] = 'payment.authorization';
        $authorization['data']['status'] = 'AUTHORIZED';
        $authorization['data']['updated'] = $occurredAt->subSecond()->utc()->format('Y-m-d\TH:i:s.up');
        unset($authorization['data']['captures']);

        return [$authorization, $capture];
    }

    /**
     * @return array<string, mixed>
     */
    private function failurePayload(Institution $institution, Invoice $invoice, CarbonImmutable $occurredAt): array
    {
        $payload = $this->capturePayload($institution, $invoice, $occurredAt);
        $payload['event'] = 'payment.failure';
        $payload['data']['status'] = 'FAILED';
        unset($payload['data']['captures']);

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function refundPayloads(Institution $institution, Invoice $invoice, CarbonImmutable $occurredAt, GatewayScenario $scenario): array
    {
        $capture = $this->capturePayload($institution, $invoice, $occurredAt->subSecond());
        $failed = $scenario === GatewayScenario::RefundFailed;
        $now = $occurredAt->utc()->format('Y-m-d\TH:i:s.up');
        $refund = [
            'event' => $failed ? 'refund.failed' : 'refund.succeeded',
            'business_id' => $institution->code,
            'created' => $now,
            'data' => [
                'id' => 'rf-'.Str::uuid(),
                'payment_id' => $capture['data']['payment_id'],
                'currency' => 'IDR',
                'amount' => $invoice->amount,
                'status' => $failed ? 'FAILED' : 'SUCCEEDED',
                'reason' => 'REQUESTED_BY_CUSTOMER',
                'failure_code' => $failed ? 'REJECTED' : null,
                'updated' => $now,
            ],
        ];

        return $scenario === GatewayScenario::RefundBeforePayment ? [$refund, $capture] : [$capture, $refund];
    }

    /**
     * A payment request that expired unpaid, shaped like Xendit's payment_request.expiry.
     *
     * @return array<string, mixed>
     */
    private function expiryPayload(Institution $institution, Invoice $invoice, CarbonImmutable $occurredAt): array
    {
        $now = $occurredAt->utc()->format('Y-m-d\TH:i:s.up');

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
    private function capturePayload(Institution $institution, Invoice $invoice, CarbonImmutable $occurredAt, ?int $amount = null): array
    {
        $now = $occurredAt->utc()->format('Y-m-d\TH:i:s.up');
        $amount ??= $invoice->amount;

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
                    'capture_amount' => $amount,
                    'capture_timestamp' => $now,
                ]],
                'created' => $now,
                'updated' => $now,
            ],
        ];
    }
}
