<?php

use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\ListCompaniesTool;
use App\Models\Company;

test('list_companies returns every company', function () {
    Company::factory()->count(3)->create();

    $response = BiglinsServer::tool(ListCompaniesTool::class, []);

    $response->assertOk();
    $response->assertStructuredContent(fn ($json) => $json->count('companies', 3)->etc());
});

test('list_companies filters by the search term', function () {
    Company::factory()->create(['name' => 'Acme Corp']);
    Company::factory()->create(['name' => 'Globex Inc']);

    $response = BiglinsServer::tool(ListCompaniesTool::class, [
        'search' => 'Acme',
    ]);

    $response->assertOk();
    $response->assertStructuredContent(fn ($json) => $json
        ->count('companies', 1)
        ->where('companies.0.name', 'Acme Corp')
        ->etc());
});

test('list_companies exposes the default company flag', function () {
    Company::factory()->create(['name' => 'Acme Corp', 'is_default' => true]);

    $response = BiglinsServer::tool(ListCompaniesTool::class, []);

    $response->assertOk();
    $response->assertStructuredContent(fn ($json) => $json
        ->where('companies.0.is_default', true)
        ->etc());
});
