<?php

namespace Tests\Unit;

use App\Rules\Cnpj;
use PHPUnit\Framework\TestCase;

class CnpjTest extends TestCase
{
    public function test_numeric_and_alphanumeric_documents(): void
    {
        foreach (['11222333000181', '12ABC34501DE35'] as $document) {
            (new Cnpj)->validate('cnpj', $document, fn () => $this->fail('Valid CNPJ rejected: '.$document));
        }
        $this->addToAssertionCount(2);
    }

    public function test_invalid_documents(): void
    {
        foreach (['00000000000000', '11222333000182', '12ABC34501DE36', '12ABC34501DE3A', '', []] as $document) {
            $failed = false;
            (new Cnpj)->validate('cnpj', $document, function () use (&$failed) {
                $failed = true;
            });
            $this->assertTrue($failed);
        }
    }
}
