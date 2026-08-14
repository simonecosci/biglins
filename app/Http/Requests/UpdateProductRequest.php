<?php

namespace App\Http\Requests;

use App\Enums\ProductDuration;
use App\Enums\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('duration') === 'none') {
            $this->merge(['duration' => null]);
        }
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:50', Rule::unique('products', 'code')->ignore($this->route('product'))],
            'type' => ['required', new Enum(ProductType::class)],
            'duration' => ['nullable', new Enum(ProductDuration::class)],
            'description' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
