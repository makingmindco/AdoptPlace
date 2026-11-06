<?php

namespace App\Models;

use App\Enums\AdoptionRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class AdoptionRequest extends Model
{
    use HasFactory;

    protected $connection = 'mongodb';

    protected $table = 'adoption_requests';

    protected $fillable = [
        'pet_id', 'organization_id', 'adopter', 'privacy_consent_at', 'status',
        'internal_notes', 'status_history',
    ];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return [
            'adopter' => 'encrypted:array',
            'privacy_consent_at' => 'datetime',
            'status' => AdoptionRequestStatus::class,
            'status_history' => 'array',
        ];
    }

    public function pet()
    {
        return $this->belongsTo(Pet::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
