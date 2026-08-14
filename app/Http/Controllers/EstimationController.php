<?php

namespace App\Http\Controllers;

use App\Enums\EstimationStatus;
use App\Http\Controllers\Concerns\ScopesToCurrentCompany;
use App\Http\Requests\StoreEstimationRequest;
use App\Http\Requests\UpdateEstimationRequest;
use App\Models\Customer;
use App\Models\Estimation;
use App\Models\EstimationRow;
use App\Support\CurrentCompany;
use App\Support\MarkdownRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EstimationController extends Controller
{
    use ScopesToCurrentCompany;

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();
        $currentCompanyId = CurrentCompany::resolve()?->id;

        $estimations = Estimation::query()
            ->with(['customer', 'rows'])
            ->where('company_id', $currentCompanyId)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($query) => $query->where('name', 'like', "%{$search}%"));
            }))
            ->orderByDesc('number')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('estimations/Index', [
            'estimations' => $estimations,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $currentCompany = CurrentCompany::resolve();

        if ($currentCompany === null) {
            return $this->redirectToCreateCompany();
        }

        $duplicateId = is_string($id = $request->query('duplicate')) ? trim($id) : '';

        $source = $duplicateId !== ''
            ? Estimation::query()->with('rows')->find($duplicateId)
            : null;

        return Inertia::render('estimations/Create', [
            'customers' => Customer::query()->where('company_id', $currentCompany->id)->orderBy('name')->get(['id', 'name']),
            'nextNumber' => Estimation::nextNumber($currentCompany->id),
            'duplicate' => $source ? [
                ...($source->company_id === $currentCompany->id ? ['customer_id' => $source->customer_id] : []),
                'body' => $source->body,
                'language' => $source->language,
                'rows' => $source->rows->map(fn (EstimationRow $row): array => [
                    'description' => $row->description,
                    'quantity' => $row->quantity,
                    'price' => $row->price,
                    'vat_rate' => $row->vat_rate,
                    'note' => $row->note,
                ])->all(),
            ] : null,
        ]);
    }

    public function store(StoreEstimationRequest $request): RedirectResponse
    {
        $currentCompany = CurrentCompany::resolve();

        if ($currentCompany === null) {
            return $this->redirectToCreateCompany();
        }

        DB::transaction(function () use ($request, $currentCompany) {
            $estimation = Estimation::query()->create([
                ...$request->safe()->except('rows'),
                'company_id' => $currentCompany->id,
            ]);

            $estimation->rows()->createMany($request->safe()->input('rows'));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Estimation created.')]);

        return to_route('estimations.index');
    }

    public function edit(Estimation $estimation): Response
    {
        $this->authorizeCurrentCompany($estimation);

        return Inertia::render('estimations/Edit', [
            'estimation' => $estimation->load(['rows', 'attachments']),
            'customers' => Customer::query()->where('company_id', $estimation->company_id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateEstimationRequest $request, Estimation $estimation): RedirectResponse
    {
        $this->authorizeCurrentCompany($estimation);

        if ($estimation->invoice_id !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This estimation was already converted to an invoice and can no longer be edited.')]);

            return to_route('estimations.edit', $estimation);
        }

        DB::transaction(function () use ($request, $estimation) {
            $estimation->update($request->safe()->except('rows'));

            $rows = collect($this->rowsInput($request));
            $keepIds = $rows->pluck('id')->filter()->all();

            $estimation->rows()->whereNotIn('id', $keepIds)->delete();

            foreach ($rows as $row) {
                $attributes = collect($row)->except('id')->all();

                if ($rowId = $row['id'] ?? null) {
                    $estimation->rows()->whereKey($rowId)->update($attributes);
                } else {
                    $estimation->rows()->create($attributes);
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Estimation updated.')]);

        return to_route('estimations.index');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsInput(UpdateEstimationRequest $request): array
    {
        return $request->safe()->input('rows');
    }

    public function destroy(Estimation $estimation): RedirectResponse
    {
        $this->authorizeCurrentCompany($estimation);

        if ($estimation->status === EstimationStatus::Accepted || $estimation->invoice_id !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('An accepted or converted estimation cannot be deleted.')]);

            return to_route('estimations.index');
        }

        $estimation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Estimation deleted.')]);

        return to_route('estimations.index');
    }

    public function markdownPreview(Request $request): JsonResponse
    {
        return response()->json([
            'html' => MarkdownRenderer::toHtml($request->string('body')->toString()),
        ]);
    }

    public function preview(Estimation $estimation): View
    {
        App::setLocale($estimation->language);

        return view('estimations.template', [
            'estimation' => $estimation->load(['customer.country', 'company.country', 'rows']),
            'bodyHtml' => MarkdownRenderer::toHtml($estimation->body),
        ]);
    }

    public function pdf(Estimation $estimation): HttpResponse
    {
        App::setLocale($estimation->language);

        return Pdf::loadView('estimations.template', [
            'estimation' => $estimation->load(['customer.country', 'company.country', 'rows']),
            'bodyHtml' => MarkdownRenderer::toHtml($estimation->body),
        ])->download(str_replace(['/', '\\'], '-', $estimation->number).'.pdf');
    }
}
