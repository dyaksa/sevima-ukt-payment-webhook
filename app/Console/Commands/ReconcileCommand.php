<?php

namespace App\Console\Commands;

use App\Actions\ReconcileGatewayBook;
use App\Enums\ReconciliationDifference;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

#[Signature('reconcile:run
    {--hours=48 : Look back this many hours by event time}
    {--apply : Apply only missing gateway events}')]
#[Description('Compare the mock gateway book with each institution ledger')]
class ReconcileCommand extends Command
{
    public function handle(ReconcileGatewayBook $reconcileGatewayBook): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($hours === false || $hours > intdiv(now()->getTimestamp(), 3600)) {
            $this->components->error('The lookback hours must be a positive integer within the supported date range.');

            return self::FAILURE;
        }

        try {
            $differences = $reconcileGatewayBook->handle($hours, (bool) $this->option('apply'));
        } catch (ValidationException|JsonException|RuntimeException $exception) {
            report($exception);
            $this->components->error('Reconciliation failed. '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($differences as $difference) {
            $this->line(implode(' | ', [
                $difference['difference']->value,
                $difference['institution_code'],
                $difference['invoice_number'] ?? '(unmatched)',
                $difference['idempotency_key'],
                $difference['outcome']?->value ?? 'report only',
                ...($difference['difference'] === ReconciliationDifference::AmountMismatch ? [
                    'gateway='.(string) ($difference['gateway_amount'] ?? 'none'),
                    'ledger='.(string) ($difference['ledger_amount'] ?? 'none'),
                ] : []),
            ]));
        }

        if ($differences === []) {
            $this->components->info('No differences.');
        }

        return self::SUCCESS;
    }
}
