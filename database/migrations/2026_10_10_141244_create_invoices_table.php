<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number', 64);
            $table->string('student_number', 32);
            $table->string('semester', 9);
            $table->bigInteger('amount');
            $table->string('status', 16)->default('unpaid');
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->unique(['institution_id', 'invoice_number']);
            $table->index(['institution_id', 'student_number', 'semester']);
        });

        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_known CHECK (status IN ('unpaid', 'paid', 'expired', 'refunded'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
