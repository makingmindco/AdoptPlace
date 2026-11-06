<?php

namespace App\Models;

use App\Enums\PetStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class Pet extends Model
{
    use HasFactory;

    protected $connection = 'mongodb';

    protected $table = 'pets';

    protected $fillable = [
        'organization_id', 'name', 'slug', 'species', 'breed', 'sex', 'size',
        'birth_date', 'approximate_age', 'description', 'personality', 'health',
        'location', 'photos', 'cover_photo', 'status', 'published_at', 'adopted_at',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'personality' => 'array',
            'health' => 'array',
            'location' => 'array',
            'photos' => 'array',
            'status' => PetStatus::class,
            'published_at' => 'datetime',
            'adopted_at' => 'datetime',
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function adoptionRequests()
    {
        return $this->hasMany(AdoptionRequest::class);
    }
}
