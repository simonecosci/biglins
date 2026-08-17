<?php

namespace App\Mcp\Tools;

use App\Http\Requests\StoreCustomerRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Support\CurrentCompany;
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

#[Name('create_customer')]
#[Description('Create a new customer under a given company.')]
class CreateCustomerTool extends Tool
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

        $company = Company::query()->findOrFail($companyId);

        return CurrentCompany::runningAs($company, function () use ($request, $company): Response|ResponseFactory {
            try {
                $data = $request->validate((new StoreCustomerRequest)->rules());
            } catch (ValidationException $e) {
                return Response::error($e->validator->errors()->first());
            }

            $customer = Customer::query()->create([
                ...$data,
                'company_id' => $company->id,
            ]);

            return Response::structured(['customer' => $customer->toArray()]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('UUID of the company to create the customer under.')->required(),
            'name' => $schema->string()->description('Customer name.')->required(),
            'address' => $schema->string()->description('Street address.'),
            'zip' => $schema->string()->description('Postal code.'),
            'city' => $schema->string(),
            'country_id' => $schema->string()->description('UUID of an existing country.'),
            'state' => $schema->string()->description('State or province.'),
            'email' => $schema->string()->format('email'),
            'web' => $schema->string()->format('uri'),
            'phone' => $schema->string(),
            'nif' => $schema->string()->description('Tax identification number.'),
        ];
    }
}
