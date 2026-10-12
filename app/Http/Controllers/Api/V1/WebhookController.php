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
     * Receive a signed gateway notification
     *
     * Sign the exact raw JSON bytes with HMAC-SHA256 using the institution's
     * database secret as the literal text key (not hex-decoded). X-Signature
     * is the lowercase hexadecimal digest. Verification precedes JSON validation.
     * The example signature is a placeholder, not a valid signed request.
     *
     * Canonical events (accepted aliases): payment.authorization (payment.pending),
     * payment.capture (payment.success), payment.failure, payment_request.expiry
     * (payment.expired), refund.succeeded (payment.refunded), and refund.failed.
     * Event names are normalized before constructing the institution-scoped key.
     * Captures sum captures[].capture_amount; refunds resolve through a prior
     * gateway payment id, ignoring any reference_id. Only IDR is accepted.
     * Every received notification is audited, including unauthorized and invalid
     * requests. Every 2xx stops retries; reconciliation can recover unmatched events.
     *
     * @group Webhooks
     *
     * @unauthenticated
     *
     * @header X-Institution-Code UNIV-A
     * @header X-Signature {RAW_BODY_HMAC}
     *
     * @bodyParam event string required Supported canonical event or alias. Example: payment.capture
     * @bodyParam business_id string required Gateway account identifier; does not select the institution. Example: UNIV-A
     * @bodyParam created string required Gateway envelope creation value; ordering uses data.updated instead. Example: 2026-10-11T01:00:00Z
     * @bodyParam data object required Event resource.
     * @bodyParam data.payment_id string Payment resource id; required for payment events and refunds, up to 64 characters. Example: py-example
     * @bodyParam data.payment_request_id string Required for expiry; up to 64 characters. Example: pr-example
     * @bodyParam data.id string Required refund resource id; up to 64 characters. No-example
     * @bodyParam data.reference_id string Required invoice number for non-refund events; up to 64 characters. Example: INV-2026-0001
     * @bodyParam data.currency string required Must be IDR. Example: IDR
     * @bodyParam data.updated string required ISO 8601 with offset; after 2000-01-01 and at most one hour ahead of the server clock. Example: 2026-10-11T01:00:00Z
     * @bodyParam data.amount integer Required for refunds; whole rupiah from 1 to 1000000000000. Canonical digit strings also accepted. No-example
     * @bodyParam data.captures object[] Required nonempty list for capture events.
     * @bodyParam data.captures[].capture_amount integer required Whole rupiah from 1 to 1000000000000; decimal and scientific notation are rejected. Example: 1500000
     *
     * @response scenario=accepted 200 {"outcome":"accepted"}
     * @response scenario=duplicate 200 {"outcome":"duplicate"}
     * @response 202 {"outcome":"unmatched"}
     * @response 401 {"message":"Invalid signature."}
     * @response 422 {"message":"Only IDR is accepted.","errors":{"data.currency":["Only IDR is accepted."]}}
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
