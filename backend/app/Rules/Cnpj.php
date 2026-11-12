<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $value) || preg_match('/^(.)\1{13}$/', $value)) {
            $fail('Informe um CNPJ válido.');

            return;
        }
        $base = substr($value, 0, 12);
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
            $sum = 0;
            foreach ($weights as $i => $weight) {
                $sum += (ord($base[$i]) - 48) * $weight;
            }
            $remainder = $sum % 11;
            $base .= $remainder < 2 ? '0' : (string) (11 - $remainder);
        }
        if ($base !== $value) {
            $fail('Informe um CNPJ válido.');
        }
    }
}
