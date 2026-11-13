<?php

namespace App\Services\Photos;

use App\Contracts\HomologationCloudinaryClient;
use Cloudinary\Api\Exception\NotFound;
use Cloudinary\Cloudinary;

class HomologationSdkClient implements HomologationCloudinaryClient
{
    private function sdk(): Cloudinary
    {
        $cloud = [];
        foreach (['cloud_name', 'api_key', 'api_secret'] as $key) {
            $value = getenv('CLOUDINARY_'.strtoupper($key));
            if (! is_string($value) || $value === '') {
                throw new HomologationDiagnostic('credentials_missing');
            }
            $cloud[$key] = $value;
        }
        // Explicit process variables only: no CLOUDINARY_URL or .env fallback.
        return new Cloudinary(['cloud' => $cloud, 'url' => ['secure' => true],
            'logging' => ['enabled' => false],
            'api' => ['connection_timeout' => 5, 'timeout' => 20, 'upload_timeout' => 20]]);
    }

    public function preflight(): void
    {
        $this->sdk(); // Constructor validates configuration without making requests.
    }

    public function upload(string $file, array $options): array
    {
        return $this->sdk()->uploadApi()->upload($file, $options)->getArrayCopy();
    }

    public function inspect(string $publicId): ?array
    {
        try {
            return $this->sdk()->adminApi()->asset($publicId, ['resource_type' => 'image', 'type' => 'upload', 'context' => true])->getArrayCopy();
        } catch (NotFound) {
            return null;
        }
    }

    public function destroy(string $publicId): array
    {
        return $this->sdk()->uploadApi()->destroy($publicId, ['resource_type' => 'image', 'type' => 'upload', 'invalidate' => true])->getArrayCopy();
    }
}
