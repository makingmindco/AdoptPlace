<?php

namespace App\Http\Requests\Auth;

use App\Rules\Cnpj;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'cnpj' => strtoupper(preg_replace('/[.\/\s-]/', '', (string) $this->input('cnpj'))),
        ]);
    }

    public function rules(): array
    {
        $provider = in_array($this->input('account_type'), ['ngo', 'independent_protector']);

        return [
            'account_type' => ['required', Rule::in(['adopter', 'ngo', 'independent_protector'])],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['bail', 'required', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'regex:/^[+()0-9\s-]{10,25}$/'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
            'provider_name' => [Rule::requiredIf($provider), 'nullable', 'string', 'max:120'],
            'description' => [Rule::requiredIf($provider), 'nullable', 'string', 'min:20', 'max:2000'],
            'has_cnpj' => ['required_if:account_type,ngo', 'boolean'],
            'cnpj' => ['exclude_unless:account_type,ngo', 'exclude_if:has_cnpj,false', 'bail', Rule::requiredIf($this->boolean('has_cnpj')), 'nullable', new Cnpj, Rule::unique('organizations', 'document')],
            'responsible_adoption' => ['exclude_if:account_type,adopter', 'accepted'],
            'address' => ['required', 'array:cep,street,number,complement,neighborhood,city,state'],
            'address.cep' => ['required', 'regex:/^[0-9]{8}$/'],
            'address.city' => ['required', 'string', 'max:100'],
            'address.state' => ['required', Rule::in(explode(',', 'AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO'))],
            'address.street' => [Rule::requiredIf($provider), 'nullable', 'string', 'max:150'],
            'address.number' => [Rule::requiredIf($provider), 'nullable', 'string', 'max:20'],
            'address.complement' => ['nullable', 'string', 'max:100'],
            'address.neighborhood' => [Rule::requiredIf($provider), 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Preencha o campo :attribute.',
            'required_if' => 'Preencha o campo :attribute.',
            'accepted' => 'Confirme o campo :attribute para continuar.',
            'email.email' => 'Informe um e-mail válido.',
            'email.unique' => 'Este e-mail já está cadastrado. Entre na sua conta.',
            'cnpj.unique' => 'Este CNPJ já está cadastrado.',
            'password.confirmed' => 'As senhas precisam ser iguais.',
            'password.min' => 'Use uma senha com pelo menos 10 caracteres.',
            'password.mixed' => 'Inclua letras maiúsculas e minúsculas na senha.',
            'password.numbers' => 'Inclua um número na senha.',
            'password.symbols' => 'Inclua um símbolo na senha.',
            'phone.regex' => 'Informe um telefone válido com DDD.',
            'address.cep.regex' => 'Informe um CEP com oito dígitos.',
            'address.state.in' => 'Selecione um estado válido.',
            'description.min' => 'Conte um pouco mais sobre seu trabalho (mínimo de 20 caracteres).',
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'phone' => 'telefone', 'provider_name' => 'nome público',
            'description' => 'apresentação', 'terms' => 'consentimento de cadastro',
            'responsible_adoption' => 'compromisso com a adoção responsável',
            'address.cep' => 'CEP', 'address.city' => 'cidade', 'address.state' => 'estado',
            'address.street' => 'logradouro', 'address.number' => 'número', 'address.neighborhood' => 'bairro'];
    }
}
