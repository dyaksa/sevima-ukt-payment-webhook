<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Institution;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'invoice_number' => 'INV-'.fake()->unique()->numerify('########'),
            'student_number' => fake()->numerify('20########'),
            'semester' => fake()->randomElement(['SM-2026-1', 'SM-2026-2']),
            'amount' => fake()->numberBetween(1, 20) * 500_000,
            'status' => InvoiceStatus::Unpaid,
            'expires_at' => now()->addMonth(),
        ];
    }
}
