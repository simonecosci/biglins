<?php

use App\Models\Company;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\QueryException;

test('note factory creates a note', function () {
    $note = Note::factory()->create();

    expect($note->id)->toBeString();
    expect(strlen($note->id))->toBe(36);
    expect($note->title)->toBeString();
    expect($note->content)->toBeString();
});

test('note belongs to a company', function () {
    $company = Company::factory()->create();
    $note = Note::factory()->create(['company_id' => $company->id]);

    expect($note->company)->toBeInstanceOf(Company::class);
    expect($note->company->id)->toBe($company->id);
});

test('a company can have many notes', function () {
    $company = Company::factory()->create();
    Note::factory()->count(2)->create(['company_id' => $company->id]);

    expect($company->fresh()->notes)->toHaveCount(2);
});

test('a note requires a company_id at the database level', function () {
    expect(fn () => Note::factory()->create(['company_id' => null]))
        ->toThrow(QueryException::class);
});

test('guests are redirected to the login page when visiting notes', function () {
    $this->get(route('notes.index'))->assertRedirect(route('login'));
});

test('notes index page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Note::factory()->count(3)->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('notes.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('notes/Index'));
});

test('notes index only lists notes for the current company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Note::factory()->count(2)->create(['company_id' => $company->id]);
    Note::factory()->count(3)->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('notes.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('notes.data', 2));
});

test('notes index renders with an empty state when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('notes.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('notes.data', 0));
});

test('notes index can be searched as json for the invoice picker', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Note::factory()->create(['company_id' => $company->id, 'title' => 'Late payment', 'content' => 'Please pay within 30 days.']);
    Note::factory()->create(['company_id' => $company->id, 'title' => 'Thanks', 'content' => 'Thank you for your business.']);
    Note::factory()->create(['company_id' => $company->id, 'title' => 'Bank details', 'content' => 'IBAN: IT00X0000000000000000000000']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->getJson(route('notes.index', ['search' => 'thank']));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.title'))->toBe('Thanks');
});

test('the notes picker json response does not include notes from another company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    Note::factory()->create(['company_id' => $company->id, 'title' => 'Mine']);
    Note::factory()->create(['company_id' => $otherCompany->id, 'title' => 'Theirs']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->getJson(route('notes.index'));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.title'))->toBe('Mine');
});

test('notes index json response is paginated', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Note::factory()->count(20)->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->getJson(route('notes.index'));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(15);
    expect($response->json('last_page'))->toBe(2);
});

test('note can be created', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('notes.store'), [
        'title' => 'Late payment',
        'content' => 'Please pay within 30 days.',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('notes.index'));
    $note = Note::query()->where('title', 'Late payment')->firstOrFail();
    expect($note->content)->toBe('Please pay within 30 days.');
    expect($note->company_id)->toBe($company->id);
});

test('note create page redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('notes.create'));

    $response->assertRedirect(route('companies.create'));
});

test('note store redirects to companies.create when there is no company yet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('notes.store'), [
        'title' => 'Late payment',
        'content' => 'Please pay within 30 days.',
    ]);

    $response->assertRedirect(route('companies.create'));
    expect(Note::query()->where('title', 'Late payment')->exists())->toBeFalse();
});

test('note store ignores a company_id sent in the payload and uses the current company instead', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();

    $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('notes.store'), [
        'company_id' => $otherCompany->id,
        'title' => 'Late payment',
        'content' => 'Please pay within 30 days.',
    ]);

    $note = Note::query()->where('title', 'Late payment')->firstOrFail();
    expect($note->company_id)->toBe($company->id);
});

test('note title is required', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('notes.store'), [
        'title' => '',
        'content' => 'Please pay within 30 days.',
    ]);

    $response->assertSessionHasErrors('title');
});

test('note content is required', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->post(route('notes.store'), [
        'title' => 'Late payment',
        'content' => '',
    ]);

    $response->assertSessionHasErrors('content');
});

test('note can be updated', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $note = Note::factory()->create(['company_id' => $company->id, 'title' => 'Old title']);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('notes.update', $note), [
        'title' => 'New title',
        'content' => $note->content,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('notes.index'));
    expect($note->fresh()->title)->toBe('New title');
});

test('note can be deleted', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $note = Note::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('notes.destroy', $note));

    $response->assertRedirect(route('notes.index'));
    expect(Note::query()->find($note->id))->toBeNull();
});

test('viewing the edit page of a note from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $note = Note::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->get(route('notes.edit', $note));

    $response->assertForbidden();
});

test('updating a note from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $note = Note::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->put(route('notes.update', $note), [
        'title' => 'Hacked title',
        'content' => $note->content,
    ]);

    $response->assertForbidden();
    expect($note->fresh()->title)->not->toBe('Hacked title');
});

test('deleting a note from another company is forbidden', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $note = Note::factory()->create(['company_id' => $otherCompany->id]);

    $response = $this->actingAs($user)->withSession(['current_company_id' => $company->id])->delete(route('notes.destroy', $note));

    $response->assertForbidden();
    expect(Note::query()->find($note->id))->not->toBeNull();
});
