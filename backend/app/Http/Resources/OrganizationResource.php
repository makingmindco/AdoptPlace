<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isMember = $request->user()
            && ((string) $request->user()->organization_id === (string) $this->id || $request->user()->isAdmin());

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'document' => $this->when($request->user()?->isAdmin(), $this->document),
            'slug' => $this->slug,
            'type' => $this->type,
            'description' => $this->description,
            'city' => $this->city,
            'state' => $this->state,
            'email' => $this->when($this->contact_publicly_visible || $isMember, $this->email),
            'phone' => $this->when($this->contact_publicly_visible || $isMember, $this->phone),
            'whatsapp' => $this->when($this->contact_publicly_visible || $isMember, $this->whatsapp),
            'social_links' => $this->social_links ?? [],
            'logo_url' => $this->logo_url,
            'contact_publicly_visible' => (bool) $this->contact_publicly_visible,
            'verification_status' => $this->when($isMember, $this->verification_status?->value ?? $this->verification_status),
            'verification_notes' => $this->when($isMember, $this->verification_notes),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
