<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(['ngo', 'independent_protector'])],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'size:2'],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'responsible_adoption' => ['accepted'],
        ];
    }
}
