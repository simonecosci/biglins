<?php

use App\Support\CountryIsoCodes;
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
        Schema::table('countries', function (Blueprint $table) {
            $table->char('iso_code', 2)->nullable()->unique()->after('name');
        });

        foreach (DB::table('countries')->get(['id', 'name']) as $country) {
            $isoCode = CountryIsoCodes::forName($country->name);

            if ($isoCode !== null) {
                DB::table('countries')->where('id', $country->id)->update(['iso_code' => $isoCode]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropUnique(['iso_code']);
            $table->dropColumn('iso_code');
        });
    }
};
