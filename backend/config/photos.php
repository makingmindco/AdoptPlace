<?php

return [
    'provider' => env('PET_MEDIA_PROVIDER', 'local'),
    'legacy_disk' => env('PET_MEDIA_LEGACY_DISK', 'public'),
    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
    ],
];
