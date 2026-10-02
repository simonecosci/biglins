<?php

namespace Database\Seeders;

use App\Support\CountryIsoCodes;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CountrySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the countries table with the standard list of world countries.
     */
    public function run(): void
    {
        $now = now();

        DB::table('countries')->insert(array_map(
            static fn (string $name, string $isoCode): array => [
                'id' => (string) Str::uuid(),
                'name' => $name,
                'iso_code' => $isoCode,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            array_keys(CountryIsoCodes::BY_NAME),
            CountryIsoCodes::BY_NAME,
        ));
    }
}
