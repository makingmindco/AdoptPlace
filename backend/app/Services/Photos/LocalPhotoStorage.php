<?php

namespace App\Services\Photos;

use App\Contracts\PhotoStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalPhotoStorage implements PhotoStorage
{
    public function upload(UploadedFile $file, string $petId): array
    {
        $disk = config('filesystems.pet_media_disk', 'public');
        $path = $file->storePublicly('pets/'.$petId, $disk);
        if (! $path) {
            throw new \RuntimeException('Photo storage failed.');
        }

        return ['id' => (string) Str::uuid(), 'provider' => 'storage', 'disk' => $disk,
            'path' => $path, 'url' => Storage::disk($disk)->url($path)];
    }

    public function delete(array $photo): void
    {
        $path = $photo['path'] ?? null;
        if (! is_string($path) || ! str_starts_with($path, 'pets/') || str_contains($path, '..')) {
            throw new \RuntimeException('Invalid photo storage path.');
        }
        $disk = Storage::disk($photo['disk'] ?? 'public');
        if (! $disk->exists($path)) {
            return;
        }
        if (! $disk->delete($path)) {
            throw new \RuntimeException('Photo deletion failed.');
        }
    }
}
