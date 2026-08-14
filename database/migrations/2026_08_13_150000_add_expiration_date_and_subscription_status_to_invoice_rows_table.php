<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_rows', function (Blueprint $table) {
            $table->date('expiration_date')->nullable()->after('vat_rate');
            $table->string('subscription_status')->default('active')->after('expiration_date');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_rows', function (Blueprint $table) {
            $table->dropColumn(['expiration_date', 'subscription_status']);
        });
    }
};
