<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The ledger is append-only and holds raw facts; findings such as an
     * amount mismatch are derived by the status calculator, never stored.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('kind', 16);
            $table->bigInteger('amount')->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->string('gateway_payment_id', 64)->nullable();
            $table->string('source', 16);
            $table->timestamp('created_at');

            $table->unique(['institution_id', 'idempotency_key']);
            $table->index(['invoice_id', 'occurred_at', 'id']);
            $table->index(['institution_id', 'gateway_payment_id']);
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_known CHECK (kind IN ('pending', 'payment', 'failed_payment', 'expiry', 'refund', 'failed_refund'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_source_known CHECK (source IN ('webhook', 'reconciliation'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
