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
        Schema::table('estimations', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('body');
            $table->string('sent_to')->nullable()->after('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimations', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'sent_to']);
        });
    }
};
