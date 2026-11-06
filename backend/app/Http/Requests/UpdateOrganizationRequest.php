<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', Rule::in(['ngo', 'independent_protector'])],
            'document' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:2000'],
            'city' => ['sometimes', 'string', 'max:100'],
            'state' => ['sometimes', 'string', 'size:2'],
            'address' => ['nullable', 'string', 'max:250'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'email:rfc', 'max:254'],
            'social_links' => ['nullable', 'array'],
            'social_links.*' => ['nullable', 'url:https', 'max:255'],
            'contact_publicly_visible' => ['sometimes', 'boolean'],
        ];
    }
}
