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
     * One append-only audit row per received notification, rejected ones
     * included. The institution is empty when the sent code is unknown.
     */
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('institution_code', 64)->nullable();
            $table->string('outcome', 16);
            $table->string('event', 64)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->string('invoice_number', 64)->nullable();
            $table->text('raw_body');
            $table->timestamp('created_at');

            $table->index(['institution_id', 'created_at']);
        });

        DB::statement("ALTER TABLE webhook_logs ADD CONSTRAINT webhook_logs_outcome_known CHECK (outcome IN ('accepted', 'duplicate', 'unmatched', 'unauthorized', 'invalid', 'error'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
