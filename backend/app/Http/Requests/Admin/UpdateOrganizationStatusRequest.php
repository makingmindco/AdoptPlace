<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrganizationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(OrganizationStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
