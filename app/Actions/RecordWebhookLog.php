<?php

namespace App\Actions;

use App\Enums\WebhookOutcome;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

class RecordWebhookLog
{
    /**
     * Bytes of the raw body kept in the audit row.
     */
    public const int MAX_BODY_BYTES = 16_384;

    /**
     * Write the audit row for one notification and the matching application
     * log line, so an incident can be analysed from either. Nothing secret is
     * stored: the signature header is left out and the body never holds one.
     *
     * Sent values are untrusted, so they are cut to their column size and made
     * valid UTF-8 without NUL bytes, which PostgreSQL text columns refuse.
     *
     * @param  array{event?: string, idempotency_key?: string, invoice_number?: string}  $details
     */
    public function handle(
        WebhookOutcome $outcome,
        ?string $institutionId,
        ?string $institutionCode,
        string $rawBody,
        array $details = [],
    ): void {
        $institutionCode = $institutionCode === null ? null : $this->clean($institutionCode, 64);

        WebhookLog::create([
            'institution_id' => $institutionId,
            'institution_code' => $institutionCode,
            'outcome' => $outcome,
            'event' => $details['event'] ?? null,
            'idempotency_key' => $details['idempotency_key'] ?? null,
            'invoice_number' => $details['invoice_number'] ?? null,
            'raw_body' => $this->clean($rawBody, self::MAX_BODY_BYTES),
        ]);

        Log::info('Webhook notification '.$outcome->value.'.', [
            'institution_code' => $institutionCode,
            'outcome' => $outcome->value,
            'idempotency_key' => $details['idempotency_key'] ?? null,
            'invoice_number' => $details['invoice_number'] ?? null,
        ]);
    }

    private function clean(string $value, int $maxBytes): string
    {
        return str_replace("\0", '', mb_scrub(mb_strcut($value, 0, $maxBytes, 'UTF-8'), 'UTF-8'));
    }
}
