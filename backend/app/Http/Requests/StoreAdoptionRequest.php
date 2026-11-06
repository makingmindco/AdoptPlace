<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdoptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['required', 'string', 'max:30'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'size:2'],
            'housing_type' => ['required', 'in:house,apartment,farm,other'],
            'has_other_pets' => ['required', 'boolean'],
            'experience' => ['nullable', 'string', 'max:1000'],
            'message' => ['required', 'string', 'min:20', 'max:2000'],
            'privacy_consent' => ['accepted'],
        ];
    }
}
