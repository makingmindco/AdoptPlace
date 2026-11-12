<?php

namespace App\Models;

use App\Casts\BsonArray;
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
        'internal_notes', 'status_history', 'user_id', 'review',
    ];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return [
            'adopter' => 'encrypted:array',
            'privacy_consent_at' => 'datetime',
            'status' => AdoptionRequestStatus::class,
            'status_history' => BsonArray::class,
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

    public static function hasApprovedForPet(string $petId, ?string $excludeRequestId = null): bool
    {
        return static::where('pet_id', $petId)
            ->when($excludeRequestId, fn ($query) => $query->whereKeyNot($excludeRequestId))
            ->where('status', AdoptionRequestStatus::Approved->value)
            ->exists();
    }

    public static function closeOpenForPet(
        string $petId,
        string $actorId,
        string $reason,
        ?string $excludeRequestId = null,
        bool $includeApproved = false,
    ): int {
        $statuses = [
            AdoptionRequestStatus::New->value,
            AdoptionRequestStatus::Reviewing->value,
            AdoptionRequestStatus::Contacted->value,
        ];

        if ($includeApproved) {
            $statuses[] = AdoptionRequestStatus::Approved->value;
        }

        $openRequests = static::where('pet_id', $petId)
            ->when($excludeRequestId, fn ($query) => $query->whereKeyNot($excludeRequestId))
            ->whereIn('status', $statuses)
            ->get();

        $closed = 0;
        $timestamp = now()->toISOString();

        foreach ($openRequests as $openRequest) {
            $history = $openRequest->status_history ?? [];
            $history[] = [
                'status' => AdoptionRequestStatus::Rejected->value,
                'changed_at' => $timestamp,
                'actor_id' => $actorId,
                'reason' => $reason,
            ];

            $closed += static::whereKey($openRequest->id)
                ->whereIn('status', $statuses)
                ->update([
                    'status' => AdoptionRequestStatus::Rejected->value,
                    'status_history' => $history,
                ]);
        }

        return $closed;
    }
}
