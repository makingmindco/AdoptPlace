<?php

namespace App\Services\Photos;

use App\Contracts\CloudinaryClient;
use App\Contracts\PhotoStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class CloudinaryPhotoStorage implements PhotoStorage
{
    public function __construct(private CloudinaryClient $client) {}

    public function upload(UploadedFile $file, string $petId): array
    {
        $id = (string) Str::uuid();
        $publicId = 'adoptplace/pets/'.$petId.'/'.$id;
        $result = $this->client->upload($file->getRealPath(), [
            'public_id' => $publicId, 'resource_type' => 'image', 'overwrite' => false,
        ]);
        $url = $result['secure_url'] ?? null;
        if (($result['public_id'] ?? null) !== $publicId || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new \RuntimeException('Invalid image provider response; remote outcome requires inspection.');
        }

        return ['id' => $id, 'provider' => 'cloudinary', 'public_id' => $publicId,
            'path' => null, 'url' => $url];
    }

    public function delete(array $photo): void
    {
        $id = $photo['public_id'] ?? '';
        if (! is_string($id) || ! str_starts_with($id, 'adoptplace/pets/') || str_contains($id, '..')) {
            throw new \RuntimeException('Invalid remote image identifier.');
        }
        $result = $this->client->destroy($id);
        if (! in_array($result['result'] ?? null, ['ok', 'not found'], true)) {
            throw new \RuntimeException('Remote image deletion failed.');
        }
    }
}
