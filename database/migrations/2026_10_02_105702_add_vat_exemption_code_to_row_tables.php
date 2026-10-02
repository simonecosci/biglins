<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoice_rows', 'estimation_rows'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('vat_exemption_code', 10)->nullable()->after('vat_rate');
            });
        }
    }

    public function down(): void
    {
        foreach (['invoice_rows', 'estimation_rows'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('vat_exemption_code');
            });
        }
    }
};
