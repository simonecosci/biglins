<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('existing invoices become issued with issued_at set to created_at', function () {
    $migration = include database_path('migrations/'.collect(scandir(database_path('migrations')))
        ->first(fn (string $file): bool => str_ends_with($file, '_add_status_to_invoices_table.php')));

    $migration->down();

    $companyId = (string) Str::uuid();
    $customerId = (string) Str::uuid();
    DB::table('companies')->insert(['id' => $companyId, 'name' => 'ACME', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('customers')->insert(['id' => $customerId, 'company_id' => $companyId, 'name' => 'Bob', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('invoices')->insert([
        'id' => (string) Str::uuid(),
        'number' => '2026-0001',
        'type' => 'invoice',
        'invoice_date' => '2026-03-01',
        'paid' => false,
        'customer_id' => $customerId,
        'company_id' => $companyId,
        'language' => 'en',
        'created_at' => '2026-03-01 10:00:00',
        'updated_at' => '2026-03-01 10:00:00',
    ]);

    $migration->up();

    $invoice = DB::table('invoices')->first();

    expect($invoice->status)->toBe('issued');
    expect($invoice->issued_at)->toBe('2026-03-01 10:00:00');
    expect($invoice->number)->toBe('2026-0001');
});
