<?php

use App\Models\Estimation;
use App\Models\EstimationRow;
use App\Models\User;
use App\Support\MarkdownRenderer;
use Illuminate\Support\Facades\App;

function renderEstimationTemplate(Estimation $estimation): string
{
    App::setLocale($estimation->language);

    return view('estimations.template', [
        'estimation' => $estimation->load(['customer.country', 'company.country', 'rows']),
        'bodyHtml' => MarkdownRenderer::toHtml($estimation->body),
    ])->render();
}

test('template renders company data, customer data, rows, and the rendered proposal body', function () {
    $estimation = Estimation::factory()->create(['language' => 'en', 'body' => '**Important** proposal']);
    EstimationRow::factory()->create([
        'estimation_id' => $estimation->id,
        'description' => 'Consulting work',
        'price' => 100,
        'vat_rate' => 22,
    ]);

    $html = renderEstimationTemplate($estimation);

    expect($html)->toContain('Estimate');
    expect($html)->toContain($estimation->number);
    expect($html)->toContain(e($estimation->company->name));
    expect($html)->toContain(e($estimation->customer->name));
    expect($html)->toContain('Consulting work');
    expect($html)->toContain('<strong>Important</strong> proposal');
});

test('template labels switch per estimation language', function () {
    $it = Estimation::factory()->create(['language' => 'it']);
    $es = Estimation::factory()->create(['language' => 'es']);
    EstimationRow::factory()->create(['estimation_id' => $it->id]);
    EstimationRow::factory()->create(['estimation_id' => $es->id]);

    expect(renderEstimationTemplate($it))->toContain('Preventivo');
    expect(renderEstimationTemplate($es))->toContain('Presupuesto');
});

test('template omits the proposal section when the body is empty', function () {
    $estimation = Estimation::factory()->create(['language' => 'en', 'body' => null]);
    EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $html = renderEstimationTemplate($estimation);

    expect($html)->not->toContain('id="proposal"');
});

test('guests are redirected to the login page when previewing an estimation', function () {
    $estimation = Estimation::factory()->create();

    $this->get(route('estimations.preview', $estimation))->assertRedirect(route('login'));
});

test('estimation preview renders as html', function () {
    $user = User::factory()->create();
    $estimation = Estimation::factory()->create(['language' => 'en']);
    EstimationRow::factory()->create(['estimation_id' => $estimation->id, 'description' => 'Design work']);

    $response = $this->actingAs($user)->get(route('estimations.preview', $estimation));

    $response->assertOk();
    $response->assertSee($estimation->number);
    $response->assertSee('Design work');
});

test('estimation pdf downloads as a pdf file', function () {
    $user = User::factory()->create();
    $estimation = Estimation::factory()->create();
    EstimationRow::factory()->create(['estimation_id' => $estimation->id]);

    $response = $this->actingAs($user)->get(route('estimations.pdf', $estimation));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain($estimation->number);
});
