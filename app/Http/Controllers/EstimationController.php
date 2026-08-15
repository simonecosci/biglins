<?php

namespace App\Http\Controllers;

use App\Enums\EstimationStatus;
use App\Http\Controllers\Concerns\ScopesToCurrentCompany;
use App\Http\Requests\StoreEstimationRequest;
use App\Http\Requests\UpdateEstimationRequest;
use App\Models\Customer;
use App\Models\Estimation;
use App\Models\EstimationRow;
use App\Models\Invoice;
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
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

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
            'estimation' => $estimation->load(['rows', 'attachments'])->append('is_expired'),
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

    public function convertToInvoice(Estimation $estimation): RedirectResponse
    {
        $this->authorizeCurrentCompany($estimation);

        if ($estimation->status !== EstimationStatus::Accepted || $estimation->invoice_id !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only an accepted, not yet converted estimation can become an invoice.')]);

            return to_route('estimations.edit', $estimation);
        }

        $invoice = DB::transaction(function () use ($estimation): Invoice {
            $invoice = Invoice::query()->create([
                'company_id' => $estimation->company_id,
                'customer_id' => $estimation->customer_id,
                'invoice_date' => now()->toDateString(),
                'paid' => false,
                'language' => $estimation->language,
            ]);

            foreach ($estimation->rows as $row) {
                $invoice->rows()->create([
                    'description' => $row->description,
                    'quantity' => $row->quantity,
                    'price' => $row->price,
                    'vat_rate' => $row->vat_rate,
                ]);
            }

            $estimation->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Estimation converted to invoice.')]);

        return to_route('invoices.edit', $invoice);
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

    public function zip(Estimation $estimation): BinaryFileResponse
    {
        $this->authorizeCurrentCompany($estimation);

        App::setLocale($estimation->language);

        $estimation->load(['customer.country', 'company.country', 'rows', 'attachments']);

        $pdfContent = Pdf::loadView('estimations.template', [
            'estimation' => $estimation,
            'bodyHtml' => MarkdownRenderer::toHtml($estimation->body),
        ])->output();

        $zipPath = tempnam(sys_get_temp_dir(), 'estimation-zip-');
        $number = str_replace(['/', '\\'], '-', $estimation->number);

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString("{$number}.pdf", $pdfContent);

        foreach ($estimation->attachments as $attachment) {
            $zip->addFile(Storage::disk($attachment->disk)->path($attachment->path), $attachment->original_name);
        }

        $zip->close();

        return response()->download($zipPath, "{$number}.zip")->deleteFileAfterSend();
    }
}
