<?php

namespace App\Actions;

use App\Enums\ReconciliationDifference;
use App\Enums\TransactionSource;
use App\Enums\WebhookOutcome;
use App\GatewayBook;
use App\Models\Institution;
use App\Models\Scopes\InstitutionScope;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReconcileGatewayBook
{
    public function __construct(
        private GatewayBook $gatewayBook,
        private ValidateGatewayNotification $validateGatewayNotification,
        private ProcessWebhookNotification $processWebhookNotification,
    ) {}

    /**
     * @return list<array{difference: ReconciliationDifference, institution_code: string, invoice_number: ?string, idempotency_key: string, outcome: ?WebhookOutcome, gateway_amount?: ?int, ledger_amount?: ?int}>
     */
    public function handle(int $hours = 48, bool $apply = false): array
    {
        $until = CarbonImmutable::now();
        $since = $until->subHours($hours);
        $institutions = Institution::orderBy('id')->get()->keyBy('code');
        $institutionCodes = $institutions->keys()->all();
        $entries = [];
        $bookKeys = [];
        $ledgerKeys = [];

        foreach ($institutions as $institution) {
            $ledgerKeys[$institution->code] = Context::scope(
                fn (): array => array_fill_keys(Transaction::whereBetween('occurred_at', [$since, $until])->pluck('idempotency_key')->all(), true),
                [InstitutionScope::CONTEXT_KEY => $institution->id],
            );
        }

        // ponytail: JSONL needs a full scan; rotate or index the book when history outgrows a local mock.
        foreach ($this->gatewayBook->entries() as $entry) {
            Validator::make($entry, ['institution_code' => ['required', 'string', Rule::in($institutionCodes)]])->validate();
            $notification = $this->validateGatewayNotification->handle($entry['payload']);
            $inWindow = $notification['occurred_at']->betweenIncluded($since, $until);

            if ($inWindow || isset($ledgerKeys[$entry['institution_code']][$notification['idempotency_key']])) {
                $bookKeys[$entry['institution_code']][$notification['idempotency_key']] = true;
            }

            if ($inWindow) {
                $entry['notification'] = $notification;
                $entries[] = $entry;
            }
        }
        usort($entries, fn (array $left, array $right): int => $left['notification']['occurred_at'] <=> $right['notification']['occurred_at']
            ?: strcmp($left['notification']['idempotency_key'], $right['notification']['idempotency_key']));
        $differences = [];

        foreach ($institutions as $institution) {
            $found = Context::scope(function () use ($institution, $entries, $bookKeys, $since, $until, $apply): array {
                $differences = [];
                $invoiceConflicts = [];

                foreach ($entries as $entry) {
                    $notification = $entry['notification'];

                    if ($entry['institution_code'] !== $institution->code || ! $notification['occurred_at']->betweenIncluded($since, $until)) {
                        continue;
                    }

                    $transaction = Transaction::with('invoice')->where('idempotency_key', $notification['idempotency_key'])->first();

                    if ($transaction !== null && $notification['invoice_number'] !== null && $transaction->invoice->invoice_number !== $notification['invoice_number']) {
                        $invoiceConflicts[$notification['idempotency_key']] = true;
                        $difference = [
                            'difference' => ReconciliationDifference::MissingWebhook,
                            'institution_code' => $institution->code,
                            'invoice_number' => $notification['invoice_number'],
                            'idempotency_key' => $notification['idempotency_key'],
                            'outcome' => null,
                        ];
                        Log::warning('Reconciliation difference.', array_replace($difference, ['difference' => $difference['difference']->value, 'ledger_invoice_number' => $transaction->invoice->invoice_number]));
                        $differences[] = $difference;
                    } elseif ($transaction !== null && $transaction->amount !== $notification['amount']) {
                        $difference = [
                            'difference' => ReconciliationDifference::AmountMismatch,
                            'institution_code' => $institution->code,
                            'invoice_number' => $transaction->invoice->invoice_number,
                            'idempotency_key' => $notification['idempotency_key'],
                            'gateway_amount' => $notification['amount'],
                            'ledger_amount' => $transaction->amount,
                            'outcome' => null,
                        ];
                        Log::warning('Reconciliation difference.', array_replace($difference, ['difference' => $difference['difference']->value]));
                        $differences[] = $difference;
                    } elseif ($transaction === null) {
                        $invoiceNumber = $notification['invoice_number'] ?? Transaction::with('invoice')
                            ->where('gateway_payment_id', $notification['gateway_payment_id'])
                            ->orderBy('occurred_at')->orderBy('id')->first()?->invoice?->invoice_number;
                        $differences[] = [
                            'difference' => ReconciliationDifference::MissingWebhook,
                            'institution_code' => $institution->code,
                            'invoice_number' => $invoiceNumber,
                            'idempotency_key' => $notification['idempotency_key'],
                            'outcome' => $apply ? $this->processWebhookNotification->handle(
                                $institution, $notification, json_encode($entry['payload'], JSON_THROW_ON_ERROR), TransactionSource::Reconciliation,
                            ) : null,
                        ];
                    }
                }

                foreach (Transaction::with('invoice')->whereBetween('occurred_at', [$since, $until])->orderBy('occurred_at')->orderBy('id')->lazy() as $transaction) {
                    if (isset($bookKeys[$institution->code][$transaction->idempotency_key]) && ! isset($invoiceConflicts[$transaction->idempotency_key])) {
                        continue;
                    }

                    $difference = [
                        'difference' => ReconciliationDifference::MissingGatewayEvent,
                        'institution_code' => $institution->code,
                        'invoice_number' => $transaction->invoice->invoice_number,
                        'idempotency_key' => $transaction->idempotency_key,
                        'outcome' => null,
                    ];
                    Log::warning('Reconciliation difference.', array_replace($difference, ['difference' => $difference['difference']->value]));
                    $differences[] = $difference;
                }

                return $differences;
            }, [InstitutionScope::CONTEXT_KEY => $institution->id]);
            array_push($differences, ...$found);
        }

        return $differences;
    }
}
