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
            'species' => ['required', Rule::in(['dog', 'cat', 'other'])],
            'breed' => ['nullable', 'string', 'max:100'],
            'sex' => ['required', Rule::in(['male', 'female', 'unknown'])],
            'size' => ['required', Rule::in(['small', 'medium', 'large'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'approximate_age' => ['nullable', 'string', 'max:50'],
            'description' => ['required', 'string', 'min:30', 'max:4000'],
            'personality' => ['nullable', 'array', 'max:10'],
            'personality.*' => ['string', 'max:40'],
            'health' => ['nullable', 'array'],
            'health.neutered' => ['nullable', 'boolean'],
            'health.vaccinated' => ['nullable', 'boolean'],
            'health.dewormed' => ['nullable', 'boolean'],
            'health.special_needs' => ['nullable', 'boolean'],
            'health.notes' => ['nullable', 'string', 'max:1000'],
            'location' => ['required', 'array'],
            'location.city' => ['required', 'string', 'max:100'],
            'location.state' => ['required', 'string', 'size:2'],
            'location.neighborhood' => ['nullable', 'string', 'max:100'],
        ];
    }
}
