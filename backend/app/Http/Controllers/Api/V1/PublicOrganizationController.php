<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;

class PublicOrganizationController extends Controller
{
    public function show(string $slug): OrganizationResource
    {
        $organization = Organization::where('slug', $slug)
            ->where('verification_status', OrganizationStatus::Approved->value)
            ->firstOrFail();

        return new OrganizationResource($organization);
    }
}
