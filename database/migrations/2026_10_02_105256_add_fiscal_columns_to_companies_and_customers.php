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
        Schema::table('companies', function (Blueprint $table) {
            $table->renameColumn('tax_id', 'vat_number');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('tax_code', 50)->nullable()->after('vat_number');
            $table->string('province', 10)->nullable()->after('city');
            $table->json('fiscal_details')->nullable();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('nif', 'vat_number');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('tax_code', 50)->nullable()->after('vat_number');
            $table->json('fiscal_details')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['tax_code', 'province', 'fiscal_details']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->renameColumn('vat_number', 'tax_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['tax_code', 'fiscal_details']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('vat_number', 'nif');
        });
    }
};
