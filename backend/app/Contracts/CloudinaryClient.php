<?php

namespace App\Contracts;

interface CloudinaryClient
{
    public function upload(string $path, array $options): array;

    public function destroy(string $publicId): array;
}
