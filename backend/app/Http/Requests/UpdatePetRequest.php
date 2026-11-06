<?php

namespace App\Http\Requests;

class UpdatePetRequest extends StorePetRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        foreach ($rules as $field => $fieldRules) {
            if (! str_contains($field, '.')) {
                array_unshift($rules[$field], 'sometimes');
                $rules[$field] = array_values(array_filter($rules[$field], fn ($rule) => $rule !== 'required'));
            }
        }

        $rules['location.city'] = ['required_with:location', 'string', 'max:100'];
        $rules['location.state'] = ['required_with:location', 'string', 'size:2'];

        return $rules;
    }
}
