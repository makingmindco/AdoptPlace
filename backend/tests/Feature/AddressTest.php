<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AddressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_valid_cep_returns_normalized_address_and_is_cached(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response([
            'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP',
        ])]);
        for ($i = 0; $i < 2; $i++) {
            $this->getJson('/api/v1/address/cep/01001000')->assertOk()
                ->assertJson(['cep' => '01001000', 'street' => 'Praça da Sé', 'city' => 'São Paulo', 'state' => 'SP']);
        }
        Http::assertSentCount(1);
    }

    public function test_invalid_cep_does_not_call_the_provider(): void
    {
        Http::fake();
        $this->getJson('/api/v1/address/cep/123')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_missing_cep_and_provider_outage_have_actionable_errors(): void
    {
        Http::fake(['viacep.com.br/*' => Http::sequence()->push(['erro' => true])->push([], 500)]);
        $this->getJson('/api/v1/address/cep/00000000')->assertNotFound();
        $this->getJson('/api/v1/address/cep/01001000')->assertStatus(503)
            ->assertJson(['message' => 'Consulta indisponível. Preencha o endereço manualmente.']);
    }
}
