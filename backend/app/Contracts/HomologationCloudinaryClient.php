<?php

namespace App\Contracts;

interface HomologationCloudinaryClient
{
    /** Validate configuration locally; must not perform network I/O. */
    public function preflight(): void;
    public function upload(string $file, array $options): array;
    public function inspect(string $publicId): ?array;
    public function destroy(string $publicId): array;
}
