<?php

namespace App\Mcp\Tools;

use App\Models\Estimation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_estimations')]
#[Description('List estimations for a given company.')]
class ListEstimationsTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $companyId = $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            ])['company_id'];
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $estimations = Estimation::query()
            ->with('customer')
            ->where('company_id', $companyId)
            ->orderByDesc('number')
            ->limit(50)
            ->get(['id', 'number', 'customer_id', 'estimation_date', 'expiration_date', 'status'])
            ->map(fn (Estimation $estimation): array => [
                'id' => $estimation->id,
                'number' => $estimation->number,
                'customer_id' => $estimation->customer_id,
                'customer_name' => $estimation->customer->name,
                'estimation_date' => $estimation->estimation_date->format('Y-m-d'),
                'expiration_date' => $estimation->expiration_date->format('Y-m-d'),
                'status' => $estimation->status->value,
            ]);

        return Response::structured([
            'estimations' => $estimations->toArray(),
            'truncated' => $estimations->count() === 50,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company to list estimations for.')->required(),
        ];
    }
}
