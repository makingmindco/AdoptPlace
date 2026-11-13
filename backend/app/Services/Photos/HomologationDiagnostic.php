<?php

namespace App\Services\Photos;

final class HomologationDiagnostic extends \RuntimeException
{
    public function __construct(public readonly string $diagnostic)
    {
        parent::__construct('Local homologation validation failed.');
    }
}
