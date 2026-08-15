<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCurrentCompany;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Country;
use App\Models\Customer;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    use ScopesToCurrentCompany;

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();
        $currentCompanyId = CurrentCompany::resolve()?->id;

        $customers = Customer::query()
            ->with('country')
            ->where('company_id', $currentCompanyId)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('customers/Index', [
            'customers' => $customers,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        if (CurrentCompany::resolve() === null) {
            return $this->redirectToCreateCompany();
        }

        return Inertia::render('customers/Create', [
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $currentCompany = CurrentCompany::resolve();

        if ($currentCompany === null) {
            return $this->redirectToCreateCompany();
        }

        Customer::query()->create([
            ...$request->validated(),
            'company_id' => $currentCompany->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer created.')]);

        return to_route('customers.index');
    }

    public function edit(Customer $customer): Response
    {
        $this->authorizeCurrentCompany($customer);

        return Inertia::render('customers/Edit', [
            'customer' => $customer,
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCurrentCompany($customer);

        $customer->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer updated.')]);

        return to_route('customers.index');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorizeCurrentCompany($customer);

        $customer->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer deleted.')]);

        return to_route('customers.index');
    }
}
