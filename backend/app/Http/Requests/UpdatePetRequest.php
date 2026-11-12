<?php

namespace App\Http\Requests;

use App\Enums\PetStatus;
use Illuminate\Validation\Rule;

class UpdatePetRequest extends StorePetRequest
{
    public function rules(): array
    {
        $pet = $this->route('pet');
        $isDraft = ! $pet || $pet->status === PetStatus::Draft;

        if ($isDraft) {
            $rules = parent::rules();
            $rules['name'] = ['sometimes', 'required', 'string', 'max:80'];

            return $rules;
        }

        return [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'species' => ['sometimes', 'required', Rule::in(['dog', 'cat', 'other'])],
            'breed' => ['nullable', 'string', 'max:100'],
            'sex' => ['sometimes', 'required', Rule::in(['male', 'female', 'unknown'])],
            'size' => ['sometimes', 'required', Rule::in(['small', 'medium', 'large'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'approximate_age' => ['nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'required', 'string', 'min:30', 'max:4000'],
            'personality' => ['nullable', 'array', 'max:10'],
            'personality.*' => ['string', 'max:40'],
            'health' => ['nullable', 'array'],
            'health.neutered' => ['nullable', 'boolean'],
            'health.vaccinated' => ['nullable', 'boolean'],
            'health.dewormed' => ['nullable', 'boolean'],
            'health.special_needs' => ['nullable', 'boolean'],
            'health.notes' => ['nullable', 'string', 'max:1000'],
            'location' => ['sometimes', 'required', 'array'],
            'location.city' => ['required_with:location', 'string', 'max:100'],
            'location.state' => ['required_with:location', 'string', 'size:2'],
            'location.neighborhood' => ['nullable', 'string', 'max:100'],
        ];
    }
}
