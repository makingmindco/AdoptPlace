<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;

class PublicOrganizationController extends Controller
{
    public function pets(string $slug)
    {
        $organization = Organization::where('slug', $slug)->where('verification_status', OrganizationStatus::Approved->value)->firstOrFail();
        return \App\Http\Resources\PetResource::collection($organization->pets()->with('organization')->where('status', 'published')->latest('published_at')->paginate(12));
    }

    public function show(string $slug): OrganizationResource
    {
        $organization = Organization::where('slug', $slug)
            ->where('verification_status', OrganizationStatus::Approved->value)
            ->firstOrFail();

        return new OrganizationResource($organization);
    }
}
