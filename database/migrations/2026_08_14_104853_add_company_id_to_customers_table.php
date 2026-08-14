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
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignUuid('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        $defaultCompanyId = DB::table('companies')->where('is_default', true)->value('id')
            ?? DB::table('companies')->orderBy('name')->value('id');

        if ($defaultCompanyId !== null) {
            DB::table('customers')->update(['company_id' => $defaultCompanyId]);
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('company_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
