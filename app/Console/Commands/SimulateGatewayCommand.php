<?php

namespace App\Console\Commands;

use App\Actions\SimulateGatewayNotification;
use App\Enums\GatewayScenario;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;

#[Signature('gateway:simulate
    {scenario : success, bad-signature, expiry, retry, wrong-amount, or expiry-vs-success}
    {institution : Code of the institution that owns the invoice}
    {invoice : Invoice number}
    {--url= : Webhook URL; defaults to this application\'s webhook route}
    {--occurred-at= : ISO 8601 event time with an offset; defaults to now}')]
#[Description('Send a signed, Xendit-shaped gateway notification to the webhook as the mock gateway')]
class SimulateGatewayCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SimulateGatewayNotification $simulateGatewayNotification): int
    {
        $scenario = GatewayScenario::tryFrom($this->argument('scenario'));

        if ($scenario === null) {
            $this->components->error('Unknown scenario. Use one of: '.implode(', ', array_column(GatewayScenario::cases(), 'value')).'.');

            return self::FAILURE;
        }

        $occurredAt = $this->option('occurred-at');
        $validator = Validator::make(['occurred_at' => $occurredAt], [
            'occurred_at' => ['nullable', 'date_format:Y-m-d\TH:i:sp,Y-m-d\TH:i:s.vp,Y-m-d\TH:i:s.up,Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP,Y-m-d\TH:i:s.uP'],
        ]);

        if (($occurredAt !== null && trim($occurredAt) === '') || $validator->fails()) {
            $this->components->error('The event time must be an ISO 8601 timestamp with an offset.');

            return self::FAILURE;
        }

        try {
            $responses = $simulateGatewayNotification->handle(
                $scenario,
                $this->argument('institution'),
                $this->argument('invoice'),
                $this->option('url') ?: route('v1.webhooks.store'),
                $occurredAt === null ? null : CarbonImmutable::parse($occurredAt),
            );
        } catch (ModelNotFoundException) {
            $this->components->error("No invoice [{$this->argument('invoice')}] for institution [{$this->argument('institution')}].");

            return self::FAILURE;
        }

        foreach ($responses as $response) {
            $this->components->info("Webhook answered {$response->status()}.");
            $this->line($response->body());
        }

        return self::SUCCESS;
    }
}
