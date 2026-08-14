<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEstimationAttachmentRequest extends FormRequest
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'rtf', 'md'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file', 'max:10240',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $extension = strtolower($value->getClientOriginalExtension());

                    if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                        $fail(__('The file must be one of the following types: :types.', ['types' => implode(', ', self::ALLOWED_EXTENSIONS)]));
                    }
                },
            ],
        ];
    }
}
