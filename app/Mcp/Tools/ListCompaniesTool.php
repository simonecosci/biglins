<?php

namespace App\Mcp\Tools;

use App\Models\Company;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_companies')]
#[Description('List the available companies, optionally filtered by a search term matched against name, tax id or email. Use this to discover the company_id required by every other tool.')]
#[IsReadOnly]
class ListCompaniesTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $data = $request->validate([
                'search' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $search = trim($data['search'] ?? '');

        $companies = Company::query()
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'tax_id', 'email', 'city', 'country_id', 'is_default']);

        return Response::structured([
            'companies' => $companies->toArray(),
            'truncated' => $companies->count() === 50,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Optional filter matched against company name, tax id or email.'),
        ];
    }
}
