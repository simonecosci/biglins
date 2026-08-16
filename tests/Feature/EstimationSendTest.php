<?php

use App\Mail\EstimationMail;
use App\Models\Company;
use App\Models\Estimation;
use App\Models\EstimationRow;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('guests are redirected to the login page when sending an estimation email', function () {
    $estimation = Estimation::factory()->create();

    $this->post(route('estimations.send', $estimation), [
        'to' => 'customer@example.com',
        'subject' => 'Your estimate',
        'message' => 'Please find your estimate attached.',
    ])->assertRedirect(route('login'));
});

test('sending an estimation emails the recipient with the zip attached and records the send', function () {
    Mail::fake();
    $user = User::factory()->create();
    $estimation = Estimation::factory()->create();
    EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $response = $this->actingAs($user)->post(route('estimations.send', $estimation), [
        'to' => 'customer@example.com',
        'subject' => 'Your estimate',
        'message' => 'Please find your estimate attached.',
    ]);

    $response->assertRedirect(route('estimations.edit', $estimation));

    Mail::assertSent(EstimationMail::class, function (EstimationMail $mail) use ($estimation) {
        return $mail->hasTo('customer@example.com')
            && $mail->envelope()->subject === 'Your estimate'
            && $mail->estimation->is($estimation)
            && count($mail->attachments()) === 1;
    });

    $estimation->refresh();
    expect($estimation->sent_to)->toBe('customer@example.com');
    expect($estimation->sent_at)->not->toBeNull();
});

test('sending an estimation requires a recipient, subject and message', function () {
    Mail::fake();
    $user = User::factory()->create();
    $estimation = Estimation::factory()->create();
    EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $response = $this->actingAs($user)->post(route('estimations.send', $estimation), [
        'to' => '',
        'subject' => '',
        'message' => '',
    ]);

    $response->assertInvalid(['to', 'subject', 'message']);
    Mail::assertNothingSent();
});

test('sending an estimation from another company is forbidden', function () {
    Mail::fake();
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $otherCompany->id]);
    EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.send', $estimation), [
        'to' => 'customer@example.com',
        'subject' => 'Your estimate',
        'message' => 'Please find your estimate attached.',
    ]);

    $response->assertForbidden();
    Mail::assertNothingSent();
});

test('the estimation mail renders the given message and subject', function () {
    $estimation = Estimation::factory()->create(['language' => 'en']);
    EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $mail = new EstimationMail($estimation, 'Your estimate', "Hello,\n\nPlease find it attached.");

    expect($mail->envelope()->subject)->toBe('Your estimate');
    $mail->assertSeeInHtml('Please find it attached.');
    expect($mail->attachments())->toHaveCount(1);
});
