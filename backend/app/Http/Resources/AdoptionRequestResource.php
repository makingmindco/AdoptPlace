<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdoptionRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'pet_id' => (string) $this->pet_id,
            'pet' => new PetResource($this->whenLoaded('pet')),
            'adopter' => $this->adopter,
            'status' => $this->status?->value ?? $this->status,
            'internal_notes' => $this->when($request->user()?->isAdmin() || ($request->user()?->organization_id && (string) $request->user()->organization_id === (string) $this->organization_id), $this->internal_notes),
            'review' => $this->review,
            'status_history' => $this->status_history ?? [],
            'privacy_consent_at' => $this->privacy_consent_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
