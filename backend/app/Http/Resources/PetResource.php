<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'species' => $this->species,
            'breed' => $this->breed,
            'sex' => $this->sex,
            'size' => $this->size,
            'birth_date' => $this->birth_date?->toDateString(),
            'approximate_age' => $this->approximate_age,
            'description' => $this->description,
            'personality' => $this->personality ?? [],
            'health' => $this->health ?? [],
            'location' => $this->location ?? [],
            'photos' => $this->photos ?? [],
            'cover_photo' => $this->cover_photo,
            'status' => $this->status?->value ?? $this->status,
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
            'published_at' => $this->published_at?->toISOString(),
            'adopted_at' => $this->adopted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
