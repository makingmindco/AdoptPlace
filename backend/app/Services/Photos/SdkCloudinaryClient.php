<?php

namespace App\Services\Photos;

use App\Contracts\CloudinaryClient;
use Cloudinary\Cloudinary;

class SdkCloudinaryClient implements CloudinaryClient
{
    private function sdk(): Cloudinary
    {
        foreach (['cloud_name', 'api_key', 'api_secret'] as $key) {
            if (! config('photos.cloudinary.'.$key)) {
                throw new \RuntimeException('Cloudinary configuration is incomplete.');
            }
        }

        return new Cloudinary(['cloud' => config('photos.cloudinary'), 'url' => ['secure' => true],
            'logging' => ['enabled' => false]]);
    }

    public function upload(string $path, array $options): array
    {
        return $this->sdk()->uploadApi()->upload($path, $options)->getArrayCopy();
    }

    public function destroy(string $publicId): array
    {
        return $this->sdk()->uploadApi()->destroy($publicId, ['resource_type' => 'image', 'invalidate' => true])->getArrayCopy();
    }
}
