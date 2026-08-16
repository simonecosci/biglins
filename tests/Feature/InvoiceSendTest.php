<?php

use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('guests are redirected to the login page when sending an invoice email', function () {
    $invoice = Invoice::factory()->create();

    $this->post(route('invoices.send', $invoice), [
        'to' => 'customer@example.com',
        'subject' => 'Your invoice',
        'message' => 'Please find your invoice attached.',
    ])->assertRedirect(route('login'));
});

test('sending an invoice emails the recipient with the pdf attached and records the send', function () {
    Mail::fake();
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->post(route('invoices.send', $invoice), [
        'to' => 'customer@example.com',
        'subject' => 'Your invoice',
        'message' => 'Please find your invoice attached.',
    ]);

    $response->assertRedirect(route('invoices.edit', $invoice));

    Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) use ($invoice) {
        return $mail->hasTo('customer@example.com')
            && $mail->envelope()->subject === 'Your invoice'
            && $mail->invoice->is($invoice)
            && count($mail->attachments()) === 1;
    });

    $invoice->refresh();
    expect($invoice->sent_to)->toBe('customer@example.com');
    expect($invoice->sent_at)->not->toBeNull();
});

test('sending an invoice requires a recipient, subject and message', function () {
    Mail::fake();
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->post(route('invoices.send', $invoice), [
        'to' => '',
        'subject' => '',
        'message' => '',
    ]);

    $response->assertInvalid(['to', 'subject', 'message']);
    Mail::assertNothingSent();
});

test('sending an invoice rejects an invalid email address', function () {
    Mail::fake();
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create();
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->post(route('invoices.send', $invoice), [
        'to' => 'not-an-email',
        'subject' => 'Your invoice',
        'message' => 'Please find your invoice attached.',
    ]);

    $response->assertInvalid(['to']);
    Mail::assertNothingSent();
});

test('sending an invoice from another company is forbidden', function () {
    Mail::fake();
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $invoice = Invoice::factory()->create(['company_id' => $otherCompany->id]);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('invoices.send', $invoice), [
        'to' => 'customer@example.com',
        'subject' => 'Your invoice',
        'message' => 'Please find your invoice attached.',
    ]);

    $response->assertForbidden();
    Mail::assertNothingSent();
});

test('the invoice mail renders the given message and subject', function () {
    $invoice = Invoice::factory()->create(['language' => 'en']);
    InvoiceRow::factory()->create(['invoice_id' => $invoice->id]);

    $mail = new InvoiceMail($invoice, 'Your invoice', "Hello,\n\nPlease find it attached.");

    expect($mail->envelope()->subject)->toBe('Your invoice');
    $mail->assertSeeInHtml('Please find it attached.');
    expect($mail->attachments())->toHaveCount(1);
});
