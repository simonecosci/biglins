<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        $invoices = Invoice::query()
            ->with(['customer', 'rows'])
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

    public function create(): Response
    {
        return Inertia::render('invoices/Create', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'nextNumber' => Invoice::nextNumber(),
        ]);
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $invoice = Invoice::query()->create($request->safe()->except('rows'));

            $invoice->rows()->createMany($request->safe()->input('rows'));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice created.')]);

        return to_route('invoices.index');
    }

    public function edit(Invoice $invoice): Response
    {
        return Inertia::render('invoices/Edit', [
            'invoice' => $invoice->load('rows'),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        DB::transaction(function () use ($request, $invoice) {
            $invoice->update($request->safe()->except('rows'));

            $rows = collect($request->safe()->input('rows'));
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

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $invoice->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice deleted.')]);

        return to_route('invoices.index');
    }
}
