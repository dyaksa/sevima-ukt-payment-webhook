<?php

namespace Database\Factories;

use App\Enums\TransactionKind;
use App\Enums\TransactionSource;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state: a captured payment. When persisting,
     * pass the invoice's `institution_id` along with `for($invoice)`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $paymentId = 'py-'.Str::uuid();

        return [
            'invoice_id' => Invoice::factory(),
            'idempotency_key' => 'payment.capture:'.$paymentId,
            'kind' => TransactionKind::Payment,
            'amount' => fake()->numberBetween(1, 20) * 500_000,
            'occurred_at' => now(),
            'gateway_payment_id' => $paymentId,
            'source' => TransactionSource::Webhook,
        ];
    }
}
