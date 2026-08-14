<?php

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Estimation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('guests are redirected to the login page when uploading an attachment', function () {
    $estimation = Estimation::factory()->create();

    $this->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
    ])->assertRedirect(route('login'));
});

test('an attachment can be uploaded to an estimation', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
    ]);

    $response->assertRedirect(route('estimations.edit', $estimation));
    expect($estimation->attachments)->toHaveCount(1);
    expect($estimation->attachments->first()->original_name)->toBe('quote.pdf');
    Storage::disk('local')->assertExists($estimation->attachments->first()->path);
});

test('each allowed extension is accepted', function (string $extension) {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create("file.{$extension}", 100),
    ]);

    $response->assertSessionHasNoErrors();
})->with(['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'rtf', 'md']);

test('a disallowed extension is rejected', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create('script.exe', 100),
    ]);

    $response->assertSessionHasErrors('file');
});

test('the stored file keeps the real extension instead of a mime-guessed one', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create('notes.md', 10, 'text/markdown'),
    ]);

    $path = $estimation->attachments->first()->path;
    expect($path)->toEndWith('.md');
});

test('a non-file value posted as the attachment is rejected instead of causing a server error', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.attachments.store', $estimation), [
        'file' => 'not-a-file',
    ]);

    $response->assertSessionHasErrors('file');
});

test('a file larger than 10MB is rejected', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create('big.pdf', 10241),
    ]);

    $response->assertSessionHasErrors('file');
});

test('uploading to an estimation from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('estimations.attachments.store', $estimation), [
        'file' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
    ]);

    $response->assertForbidden();
    expect($estimation->attachments)->toHaveCount(0);
});

test('an attachment can be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $company->id]);
    Storage::disk('local')->put('estimations/x/attachments/file.pdf', 'content');
    $attachment = Attachment::factory()->create([
        'attachable_type' => Estimation::class,
        'attachable_id' => $estimation->id,
        'disk' => 'local',
        'path' => 'estimations/x/attachments/file.pdf',
    ]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('estimations.attachments.destroy', [$estimation, $attachment]));

    $response->assertRedirect(route('estimations.edit', $estimation));
    expect(Attachment::query()->find($attachment->id))->toBeNull();
    Storage::disk('local')->assertMissing('estimations/x/attachments/file.pdf');
});

test('deleting an attachment from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $estimation = Estimation::factory()->create(['company_id' => $otherCompany->id]);
    $attachment = Attachment::factory()->create([
        'attachable_type' => Estimation::class,
        'attachable_id' => $estimation->id,
    ]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('estimations.attachments.destroy', [$estimation, $attachment]));

    $response->assertForbidden();
    expect(Attachment::query()->find($attachment->id))->not->toBeNull();
});
