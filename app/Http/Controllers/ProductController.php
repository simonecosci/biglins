<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $currentCompanyId = CurrentCompany::resolve()?->id;

        $products = Product::query()
            ->where('company_id', $currentCompanyId)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }))
            ->orderBy('description')
            ->paginate(15)
            ->withQueryString();

        if ($request->wantsJson()) {
            return response()->json($products);
        }

        return Inertia::render('products/Index', [
            'products' => $products,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        if (CurrentCompany::resolve() === null) {
            return $this->redirectToCreateCompany();
        }

        return Inertia::render('products/Create');
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $currentCompany = CurrentCompany::resolve();

        if ($currentCompany === null) {
            return $this->redirectToCreateCompany();
        }

        Product::query()->create([
            ...$request->validated(),
            'company_id' => $currentCompany->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product created.')]);

        return to_route('products.index');
    }

    public function edit(Product $product): Response
    {
        $this->authorizeCurrentCompany($product);

        return Inertia::render('products/Edit', [
            'product' => $product,
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorizeCurrentCompany($product);

        $product->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product updated.')]);

        return to_route('products.index');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorizeCurrentCompany($product);

        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product deleted.')]);

        return to_route('products.index');
    }

    private function authorizeCurrentCompany(Product $product): void
    {
        abort_unless($product->company_id === CurrentCompany::resolve()?->id, 403);
    }

    private function redirectToCreateCompany(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('Create a company before you can manage invoices or products.')]);

        return to_route('companies.create');
    }
}
