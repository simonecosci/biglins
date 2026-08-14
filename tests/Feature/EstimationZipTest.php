<?php

use App\Models\Attachment;
use App\Models\Estimation;
use App\Models\EstimationRow;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('guests are redirected to the login page when downloading an estimation zip', function () {
    $estimation = Estimation::factory()->create();

    $this->get(route('estimations.zip', $estimation))->assertRedirect(route('login'));
});

test('the zip contains the pdf and every attachment', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $estimation = Estimation::factory()->create();
    EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    Storage::disk('local')->put('estimations/x/attachments/brief.pdf', 'brief content');
    Storage::disk('local')->put('estimations/x/attachments/logo.png', 'png content');
    Attachment::factory()->create([
        'attachable_type' => Estimation::class,
        'attachable_id' => $estimation->id,
        'disk' => 'local',
        'path' => 'estimations/x/attachments/brief.pdf',
        'original_name' => 'brief.pdf',
    ]);
    Attachment::factory()->create([
        'attachable_type' => Estimation::class,
        'attachable_id' => $estimation->id,
        'disk' => 'local',
        'path' => 'estimations/x/attachments/logo.png',
        'original_name' => 'logo.png',
    ]);

    $response = $this->actingAs($user)->get(route('estimations.zip', $estimation));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/zip');

    $tmpFile = tempnam(sys_get_temp_dir(), 'zip-test-');
    file_put_contents($tmpFile, $response->streamedContent());

    $zip = new ZipArchive;
    $zip->open($tmpFile);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    unlink($tmpFile);

    expect($names)->toContain($estimation->number.'.pdf');
    expect($names)->toContain('brief.pdf');
    expect($names)->toContain('logo.png');
});

test('the zip works for an estimation with no attachments', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $estimation = Estimation::factory()->create();
    EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $response = $this->actingAs($user)->get(route('estimations.zip', $estimation));

    $response->assertOk();
});
