<?php

namespace App\Console\Commands;

use App\Actions\SimulateGatewayNotification;
use App\Enums\GatewayScenario;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

#[Signature('gateway:simulate
    {scenario : success, bad-signature, expiry, or retry}
    {institution : Code of the institution that owns the invoice}
    {invoice : Invoice number}
    {--url= : Webhook URL; defaults to this application\'s webhook route}')]
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

        try {
            $responses = $simulateGatewayNotification->handle(
                $scenario,
                $this->argument('institution'),
                $this->argument('invoice'),
                $this->option('url') ?: route('v1.webhooks.store'),
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
