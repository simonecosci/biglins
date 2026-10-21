<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('renaming tax_id and nif to vat_number keeps the data', function () {
    $migration = include database_path('migrations/'.collect(scandir(database_path('migrations')))
        ->first(fn (string $file): bool => str_ends_with($file, '_add_fiscal_columns_to_companies_and_customers.php')));

    $migration->down();

    $companyId = (string) Str::uuid();
    DB::table('companies')->insert(['id' => $companyId, 'name' => 'ACME', 'tax_id' => 'IT01234567890', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('customers')->insert(['id' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => 'Bob', 'nif' => 'B12345678', 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    expect(DB::table('companies')->value('vat_number'))->toBe('IT01234567890');
    expect(DB::table('customers')->value('vat_number'))->toBe('B12345678');
});
