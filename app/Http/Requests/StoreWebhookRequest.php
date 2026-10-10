<?php

namespace App\Http\Requests;

use App\Actions\RecordWebhookLog;
use App\Enums\WebhookEvent;
use App\Enums\WebhookOutcome;
use App\Models\Institution;
use App\Rules\IntegerAmount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A gateway notification in Xendit's webhook shape. It is resolved only after
 * VerifyWebhookSignature accepted the request, so the institution is known.
 */
class StoreWebhookRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request. Only the capture
     * and expiry events are handled so far; later tickets widen the list.
     * Each event requires the id of its own resource.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $eventName = $this->input('event');
        $event = is_string($eventName) ? WebhookEvent::fromWireName($eventName) : null;
        $resourceIdField = $event?->resourceIdField();

        return [
            'event' => ['required', 'string', Rule::in([
                ...WebhookEvent::PaymentCapture->wireNames(),
                ...WebhookEvent::PaymentRequestExpiry->wireNames(),
            ])],
            'business_id' => ['required', 'string'],
            'created' => ['required', 'string'],
            'data' => ['required', 'array'],
            'data.payment_id' => [Rule::requiredIf($resourceIdField === 'payment_id'), 'nullable', 'string', 'max:64'],
            'data.payment_request_id' => [Rule::requiredIf($resourceIdField === 'payment_request_id'), 'nullable', 'string', 'max:64'],
            'data.reference_id' => ['required', 'string', 'max:64'],
            'data.currency' => ['required', 'string', 'in:IDR'],
            // ISO 8601 with an offset; `p` matches "Z" and `P` matches "+00:00".
            'data.updated' => ['required', 'date_format:Y-m-d\TH:i:sp,Y-m-d\TH:i:s.vp,Y-m-d\TH:i:s.up,Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP,Y-m-d\TH:i:s.uP'],
            'data.captures' => [Rule::requiredIf($event === WebhookEvent::PaymentCapture), 'array', 'min:1'],
            'data.captures.*.capture_amount' => ['required', new IntegerAmount],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event.in' => 'The event is not a supported gateway event.',
            'data.currency.in' => 'Only IDR is accepted.',
        ];
    }

    /**
     * The institution whose secret signed this request.
     */
    public function institution(): Institution
    {
        return $this->attributes->get(Institution::class);
    }

    /**
     * The validated notification in the terms of the ledger.
     *
     * Only a capture carries an amount and a payment; an expiry has neither.
     *
     * @return array{event: WebhookEvent, idempotency_key: string, invoice_number: string, amount: ?int, occurred_at: CarbonImmutable, gateway_payment_id: ?string}
     */
    public function notification(): array
    {
        $event = WebhookEvent::fromWireName($this->validated('event'));
        $isCapture = $event === WebhookEvent::PaymentCapture;

        return [
            'event' => $event,
            'idempotency_key' => $event->value.':'.$this->validated('data.'.$event->resourceIdField()),
            'invoice_number' => $this->validated('data.reference_id'),
            'amount' => $isCapture ? array_sum(array_map(
                fn (array $capture): int => (int) $capture['capture_amount'],
                $this->validated('data.captures'),
            )) : null,
            'occurred_at' => CarbonImmutable::parse($this->validated('data.updated')),
            'gateway_payment_id' => $isCapture ? $this->validated('data.payment_id') : null,
        ];
    }

    /**
     * Log the rejected notification before the 422 is returned.
     */
    protected function failedValidation(Validator $validator): void
    {
        app(RecordWebhookLog::class)->handle(
            WebhookOutcome::Invalid,
            $this->institution()->id,
            $this->institution()->code,
            $this->getContent(),
        );

        parent::failedValidation($validator);
    }
}
