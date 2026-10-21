<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('e_invoicing_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('driver', 30);
            $table->string('environment', 20);
            $table->text('credentials')->nullable();
            $table->string('webhook_secret', 64);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('invoice_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('e_invoicing_integration_id')->constrained()->restrictOnDelete();
            $table->string('driver', 30);
            $table->string('status', 20)->index();
            $table->string('provider_status')->nullable();
            $table->string('external_id')->nullable()->index();
            $table->string('authority_id')->nullable();
            $table->text('qr_code')->nullable();
            $table->text('error_message')->nullable();
            $table->string('payload_path')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_submission_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('invoice_submissions')->cascadeOnDelete();
            $table->string('type');
            $table->string('provider_event_id');
            $table->json('payload');
            $table->dateTime('received_at');
            $table->timestamps();
            $table->unique(['submission_id', 'provider_event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_submission_events');
        Schema::dropIfExists('invoice_submissions');
        Schema::dropIfExists('e_invoicing_integrations');
    }
};
