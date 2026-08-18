<?php

namespace App\Mcp\Tools;

use App\Http\Requests\StoreEstimationRequest;
use App\Models\Company;
use App\Models\Estimation;
use App\Support\CurrentCompany;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_estimation')]
#[Description('Create a new estimation with line items for an existing customer.')]
class CreateEstimationTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $companyId = (string) $request->validate([
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            ])['company_id'];
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

        $company = Company::query()->findOrFail($companyId);

        return CurrentCompany::runningAs($company, function () use ($request, $company): Response|ResponseFactory {
            try {
                $data = $request->validate((new StoreEstimationRequest)->rules());
            } catch (ValidationException $e) {
                return Response::error($e->validator->errors()->first());
            }

            $estimation = DB::transaction(function () use ($data, $company): Estimation {
                $estimation = Estimation::query()->create([
                    ...collect($data)->except('rows')->all(),
                    'company_id' => $company->id,
                ]);

                $estimation->rows()->createMany($data['rows']);

                return $estimation;
            });

            return Response::structured(['estimation' => $estimation->load('rows')->toArray()]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company to create the estimation under.')->required(),
            'customer_id' => $schema->string()->description('UUID of an existing customer belonging to the company.')->required(),
            'estimation_date' => $schema->string()->format('date')->description('YYYY-MM-DD.')->required(),
            'expiration_date' => $schema->string()->format('date')->description('YYYY-MM-DD, must be on or after estimation_date.')->required(),
            'language' => $schema->string()->enum(['it', 'en', 'es'])->required(),
            'body' => $schema->string()->description('Optional markdown body/notes.'),
            'rows' => $schema->array()->items(
                $schema->object([
                    'description' => $schema->string()->required(),
                    'quantity' => $schema->number()->required(),
                    'price' => $schema->number()->required(),
                    'vat_rate' => $schema->number()->required(),
                    'note' => $schema->string(),
                ])
            )->description('Line items, at least one required.')->required(),
        ];
    }
}
