<?php

use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\ListEstimationsTool;
use App\Models\Company;
use App\Models\Estimation;
use Illuminate\Support\Str;

test('list_estimations returns estimations scoped to the given company', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Estimation::factory()->count(2)->create(['company_id' => $company->id]);
    Estimation::factory()->create(['company_id' => $otherCompany->id]);

    $response = BiglinsServer::tool(ListEstimationsTool::class, [
        'company_id' => $company->id,
    ]);

    $response->assertOk();
    $response->assertStructuredContent(fn ($json) => $json->count('estimations', 2)->etc());
});

test('list_estimations rejects a company_id that does not exist', function () {
    $response = BiglinsServer::tool(ListEstimationsTool::class, [
        'company_id' => (string) Str::uuid(),
    ]);

    $response->assertHasErrors();
});
