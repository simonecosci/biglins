<?php

use App\Models\EstimationRow;

test('estimation row total accessor adds vat to the price', function () {
    $row = EstimationRow::factory()->create(['quantity' => 1, 'price' => 100, 'vat_rate' => 22]);

    expect((float) $row->total)->toEqual(122.0);
});

test('estimation row total accessor multiplies price by quantity before applying vat', function () {
    $row = EstimationRow::factory()->create(['quantity' => 2, 'price' => 100, 'vat_rate' => 22]);

    expect((float) $row->total)->toEqual(244.0);
});
