<?php

use App\Models\Attachment;
use App\Models\Estimation;

test('attachment factory creates an attachment with a uuid primary key', function () {
    $attachment = Attachment::factory()->create();

    expect($attachment->id)->toBeString();
    expect(strlen($attachment->id))->toBe(36);
    expect($attachment->size)->toBeInt();
});

test('attachment belongs to its attachable model via morph relation', function () {
    $estimation = Estimation::factory()->create();
    $attachment = Attachment::factory()->create([
        'attachable_type' => Estimation::class,
        'attachable_id' => $estimation->id,
    ]);

    expect($attachment->attachable)->toBeInstanceOf(Estimation::class);
    expect($attachment->attachable->id)->toBe($estimation->id);
});
