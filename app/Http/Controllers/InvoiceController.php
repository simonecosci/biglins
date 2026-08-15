<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceType;
use App\Http\Controllers\Concerns\ScopesToCurrentCompany;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    use ScopesToCurrentCompany;

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();
        $currentCompanyId = CurrentCompany::resolve()?->id;

        $invoices = Invoice::query()
            ->with(['customer', 'rows'])
            ->where('company_id', $currentCompanyId)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($query) => $query->where('name', 'like', "%{$search}%"));
            }))
            ->orderByDesc('number')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('invoices/Index', [
            'invoices' => $invoices,
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
            ? Invoice::query()->with('rows')->find($duplicateId)
            : null;

        return Inertia::render('invoices/Create', [
            'customers' => Customer::query()->where('company_id', $currentCompany->id)->orderBy('name')->get(['id', 'name']),
            'nextNumber' => Invoice::nextNumber($currentCompany->id),
            'duplicate' => $source ? [
                // A customer from another company would not be selectable here, so it is only
                // carried over when duplicating within the same company.
                ...($source->company_id === $currentCompany->id ? ['customer_id' => $source->customer_id] : []),
                'note' => $source->note,
                'language' => $source->language,
                'type' => $source->type->value,
                'rows' => $source->rows->map(fn (InvoiceRow $row): array => [
                    'description' => $row->description,
                    'quantity' => $row->quantity,
                    'price' => $source->isCreditNote() ? abs($row->price) : $row->price,
                    'vat_rate' => $row->vat_rate,
                    'expiration_date' => $row->expiration_date?->format('Y-m-d'),
                ])->all(),
            ] : null,
        ]);
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        $currentCompany = CurrentCompany::resolve();

        if ($currentCompany === null) {
            return $this->redirectToCreateCompany();
        }

        DB::transaction(function () use ($request, $currentCompany) {
            $invoice = Invoice::query()->create([
                ...$request->safe()->except('rows'),
                'company_id' => $currentCompany->id,
            ]);

            $rows = collect($this->rowsInput($request))
                ->map(fn (array $row): array => $this->normalizeRowPrice($row, $invoice->type));

            $invoice->rows()->createMany($rows);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice created.')]);

        return to_route('invoices.index');
    }

    public function edit(Invoice $invoice): Response
    {
        $this->authorizeCurrentCompany($invoice);

        $invoice->load('rows');

        if ($invoice->isCreditNote()) {
            $invoice->rows->each(fn (InvoiceRow $row) => $row->price = abs($row->price));
        }

        return Inertia::render('invoices/Edit', [
            'invoice' => $invoice,
            'customers' => Customer::query()->where('company_id', $invoice->company_id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoice);

        DB::transaction(function () use ($request, $invoice) {
            $invoice->update($request->safe()->except('rows'));

            $rows = collect($this->rowsInput($request))
                ->map(fn (array $row): array => $this->normalizeRowPrice($row, $invoice->type));
            $keepIds = $rows->pluck('id')->filter()->all();

            $invoice->rows()->whereNotIn('id', $keepIds)->delete();

            foreach ($rows as $row) {
                $attributes = collect($row)->except('id')->all();

                if ($rowId = $row['id'] ?? null) {
                    $invoice->rows()->whereKey($rowId)->update($attributes);
                } else {
                    $invoice->rows()->create($attributes);
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice updated.')]);

        return to_route('invoices.index');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsInput(StoreInvoiceRequest|UpdateInvoiceRequest $request): array
    {
        return $request->safe()->input('rows');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRowPrice(array $row, InvoiceType $type): array
    {
        if ($type === InvoiceType::CreditNote) {
            $row['price'] = -abs((float) $row['price']);
        }

        return $row;
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorizeCurrentCompany($invoice);

        $invoice->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice deleted.')]);

        return to_route('invoices.index');
    }

    public function preview(Invoice $invoice): View
    {
        App::setLocale($invoice->language);

        return view('invoices.template', [
            'invoice' => $invoice->load(['customer.country', 'company.country', 'rows']),
        ]);
    }

    public function pdf(Invoice $invoice): HttpResponse
    {
        App::setLocale($invoice->language);

        return Pdf::loadView('invoices.template', [
            'invoice' => $invoice->load(['customer.country', 'company.country', 'rows']),
        ])->download(str_replace(['/', '\\'], '-', $invoice->number).'.pdf');
    }
}
