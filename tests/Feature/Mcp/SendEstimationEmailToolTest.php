<?php

use App\Mail\EstimationMail;
use App\Mcp\Servers\BiglinsServer;
use App\Mcp\Tools\SendEstimationEmailTool;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimation;
use Illuminate\Support\Facades\Mail;

test('send_estimation_email sends the estimation and records who it was sent to', function () {
    Mail::fake();
    $company = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $company->id]);
    $estimation = Estimation::factory()->create(['company_id' => $company->id, 'customer_id' => $customer->id]);

    $response = BiglinsServer::tool(SendEstimationEmailTool::class, [
        'company_id' => $company->id,
        'estimation_id' => $estimation->id,
        'to' => 'client@example.test',
        'subject' => 'Your estimation',
        'message' => 'Please find your estimation attached.',
    ]);

    $response->assertOk();
    Mail::assertSent(EstimationMail::class);
    expect($estimation->fresh()->sent_to)->toBe('client@example.test');
    expect($estimation->fresh()->sent_at)->not->toBeNull();
});

test('send_estimation_email rejects an estimation belonging to another company', function () {
    Mail::fake();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $otherCompany->id]);

    $response = BiglinsServer::tool(SendEstimationEmailTool::class, [
        'company_id' => $company->id,
        'estimation_id' => $estimation->id,
        'to' => 'client@example.test',
        'subject' => 'Your estimation',
        'message' => 'Please find your estimation attached.',
    ]);

    $response->assertHasErrors();
    Mail::assertNotSent(EstimationMail::class);
});
