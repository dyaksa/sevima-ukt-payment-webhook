<?php

namespace App\Actions;

use App\Enums\TransactionSource;
use App\Enums\WebhookEvent;
use App\Enums\WebhookOutcome;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessWebhookNotification
{
    public function __construct(
        private CalculateInvoiceStatus $calculateInvoiceStatus,
        private RecordWebhookLog $recordWebhookLog,
    ) {}

    /**
     * Process a verified notification in one database transaction: the ledger
     * row, the recomputed invoice status, and the audit row commit together.
     * If anything fails, the transaction rolls back and an `error` audit row
     * is written on its own.
     *
     * @param  array{event: WebhookEvent, idempotency_key: string, invoice_number: string, amount: ?int, occurred_at: CarbonImmutable, gateway_payment_id: ?string}  $notification
     *
     * @throws Throwable
     */
    public function handle(Institution $institution, array $notification, string $rawBody): WebhookOutcome
    {
        $log = fn (WebhookOutcome $outcome) => $this->recordWebhookLog->handle($outcome, $institution->id, $institution->code, $rawBody, [
            'event' => $notification['event']->value,
            'idempotency_key' => $notification['idempotency_key'],
            'invoice_number' => $notification['invoice_number'],
        ]);

        try {
            return DB::transaction(function () use ($institution, $notification, $log): WebhookOutcome {
                $outcome = $this->record($institution, $notification);
                $log($outcome);

                return $outcome;
            });
        } catch (Throwable $exception) {
            $log(WebhookOutcome::Error);

            throw $exception;
        }
    }

    /**
     * Lock the invoice, append the ledger row unless its idempotency key is
     * already there, and store the status derived from the whole ledger.
     *
     * @param  array{event: WebhookEvent, idempotency_key: string, invoice_number: string, amount: ?int, occurred_at: CarbonImmutable, gateway_payment_id: ?string}  $notification
     */
    private function record(Institution $institution, array $notification): WebhookOutcome
    {
        $invoice = Invoice::query()
            ->where('invoice_number', $notification['invoice_number'])
            ->lockForUpdate()
            ->first();

        if ($invoice === null) {
            return WebhookOutcome::Unmatched;
        }

        // insertOrIgnore rather than catching the unique violation, which would abort the PostgreSQL transaction.
        $inserted = Transaction::query()->insertOrIgnore([
            'id' => (new Transaction)->newUniqueId(),
            'institution_id' => $institution->id,
            'invoice_id' => $invoice->id,
            'idempotency_key' => $notification['idempotency_key'],
            'kind' => $notification['event']->kind()->value,
            'amount' => $notification['amount'],
            'occurred_at' => $notification['occurred_at']->format('Y-m-d H:i:s.uP'),
            'gateway_payment_id' => $notification['gateway_payment_id'],
            'source' => TransactionSource::Webhook->value,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            return WebhookOutcome::Duplicate;
        }

        $invoice->update(['status' => $this->calculateInvoiceStatus->handle($invoice, $invoice->transactions()->get())]);

        return WebhookOutcome::Accepted;
    }
}
