<?php

namespace App\Models;

use App\Casts\BsonArray;
use MongoDB\Laravel\Eloquent\Model;

class PhotoDeletion extends Model
{
    protected $connection = 'mongodb';
    protected $table = 'photo_deletions';
    protected $guarded = [];
    protected $hidden = ['photo'];

    protected function casts(): array
    {
        return ['photo' => BsonArray::class];
    }

    public static function identifier(string $petId, string $photoId): string
    {
        return hash('sha256', $petId."\0".$photoId);
    }
}
