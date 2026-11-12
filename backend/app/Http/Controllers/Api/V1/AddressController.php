<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AddressController extends Controller
{
    public function show(string $cep)
    {
        abort_unless(preg_match('/^[0-9]{8}$/', $cep), 422, 'Informe um CEP com oito dígitos.');
        try {
            $data = Cache::remember('cep:'.$cep, 86400, function () use ($cep) {
                $data = Http::connectTimeout(3)->timeout(5)->get("https://viacep.com.br/ws/{$cep}/json/")->throw()->json();
                if (! is_array($data)) {
                    throw new \RuntimeException('Invalid CEP response');
                }

                return $data;
            });
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'Consulta indisponível. Preencha o endereço manualmente.'], 503);
        }
        abort_if(! empty($data['erro']), 404, 'CEP não encontrado. Confira ou preencha manualmente.');

        return response()->json(['cep' => $cep, 'street' => $data['logradouro'] ?? '', 'neighborhood' => $data['bairro'] ?? '', 'city' => $data['localidade'] ?? '', 'state' => $data['uf'] ?? '']);
    }
}
