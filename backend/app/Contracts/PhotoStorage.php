<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface PhotoStorage
{
    public function upload(UploadedFile $file, string $petId): array;

    public function delete(array $photo): void;
}
