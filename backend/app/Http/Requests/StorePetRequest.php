<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'species' => ['nullable', Rule::in(['dog', 'cat', 'other'])],
            'breed' => ['nullable', 'string', 'max:100'],
            'sex' => ['nullable', Rule::in(['male', 'female', 'unknown'])],
            'size' => ['nullable', Rule::in(['small', 'medium', 'large'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'approximate_age' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:4000'],
            'personality' => ['nullable', 'array', 'max:10'],
            'personality.*' => ['string', 'max:40'],
            'health' => ['nullable', 'array'],
            'health.neutered' => ['nullable', 'boolean'],
            'health.vaccinated' => ['nullable', 'boolean'],
            'health.dewormed' => ['nullable', 'boolean'],
            'health.special_needs' => ['nullable', 'boolean'],
            'health.notes' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'array'],
            'location.city' => ['nullable', 'string', 'max:100'],
            'location.state' => ['nullable', 'string', 'size:2'],
            'location.neighborhood' => ['nullable', 'string', 'max:100'],
        ];
    }
}
