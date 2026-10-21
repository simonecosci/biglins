<?php

use App\EInvoicing\Enums\SubmissionStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceSubmission;
use App\Models\User;

beforeEach(function () {
    $this->company = Company::factory()->create(['is_default' => true]);
    $this->actingAs(User::factory()->create());
});

test('edit exposes the submission history without qr and event payloads', function () {
    $invoice = Invoice::factory()->issued()->create(['company_id' => $this->company->id]);
    $submission = InvoiceSubmission::factory()->for($invoice)->create(['status' => SubmissionStatus::Rejected, 'error_message' => '00404 duplicate', 'qr_code' => 'QRDATA']);
    $submission->events()->create(['type' => 'status_change', 'provider_event_id' => 'e1', 'payload' => ['secret' => 'x'], 'received_at' => now()]);

    $this->get(route('invoices.edit', $invoice))
        ->assertInertia(fn ($page) => $page
            ->where('submissions.0.status', 'rejected')
            ->where('submissions.0.error_message', '00404 duplicate')
            ->where('submissions.0.events.0.type', 'status_change')
            ->missing('submissions.0.qr_code')
            ->missing('submissions.0.events.0.payload')
            ->missing('invoice.latest_submission'));
});

test('index filters by status and shows the latest submission status', function () {
    Invoice::factory()->create(['company_id' => $this->company->id]);
    $issued = Invoice::factory()->issued()->create(['company_id' => $this->company->id]);
    InvoiceSubmission::factory()->for($issued)->create(['status' => SubmissionStatus::Accepted, 'qr_code' => 'QRDATA']);

    $this->get(route('invoices.index', ['status' => 'issued']))
        ->assertInertia(fn ($page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.latest_submission.status', 'accepted')
            ->missing('invoices.data.0.latest_submission.qr_code')
            ->where('filters.status', 'issued'));
});

test('index combines search and status filters', function () {
    $customer = Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'Acme Srl']);
    Invoice::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer->id]);
    Invoice::factory()->issued()->create(['company_id' => $this->company->id, 'customer_id' => $customer->id]);
    Invoice::factory()->issued()->create(['company_id' => $this->company->id]);

    $this->get(route('invoices.index', ['search' => 'Acme', 'status' => 'issued']))
        ->assertInertia(fn ($page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.status', 'issued')
            ->where('filters.search', 'Acme'));
});

test('index ignores an invalid status filter', function () {
    Invoice::factory()->create(['company_id' => $this->company->id]);
    Invoice::factory()->issued()->create(['company_id' => $this->company->id]);

    $this->get(route('invoices.index', ['status' => 'foo']))
        ->assertInertia(fn ($page) => $page
            ->has('invoices.data', 2)
            ->where('filters.status', ''));
});
