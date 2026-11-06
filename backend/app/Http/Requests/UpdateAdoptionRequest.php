<?php

namespace App\Http\Requests;

use App\Enums\AdoptionRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdoptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AdoptionRequestStatus::class)],
            'internal_notes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
