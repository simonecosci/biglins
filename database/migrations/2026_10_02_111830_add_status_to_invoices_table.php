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
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('number')->nullable()->change();
            $table->string('status', 20)->default('draft')->after('type')->index();
            $table->dateTime('issued_at')->nullable()->after('status');
        });

        DB::table('invoices')->update([
            'status' => 'issued',
            'issued_at' => DB::raw('created_at'),
        ]);
    }

    /**
     * Reverse the migrations. The number stays nullable because drafts may exist.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'issued_at']);
        });
    }
};
