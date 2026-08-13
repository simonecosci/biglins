<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
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

        $products = Product::query()
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

    public function create(): Response
    {
        return Inertia::render('products/Create');
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        Product::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product created.')]);

        return to_route('products.index');
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('products/Edit', [
            'product' => $product,
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product updated.')]);

        return to_route('products.index');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product deleted.')]);

        return to_route('products.index');
    }
}
