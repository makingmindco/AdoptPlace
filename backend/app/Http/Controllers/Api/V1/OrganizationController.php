<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    public function show(Request $request): OrganizationResource
    {
        abort_unless($request->user()->organization_id, 403);

        return new OrganizationResource($request->user()->organization);
    }

    public function update(UpdateOrganizationRequest $request, AuditService $audit): OrganizationResource
    {
        abort_unless($request->user()->organization_id, 403);
        $organization = $request->user()->organization;
        $data = $request->validated();

        if (isset($data['name']) && $data['name'] !== $organization->name) {
            $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(5));
        }

        if (isset($data['state'])) {
            $data['state'] = strtoupper($data['state']);
        }

        $organization->update($data);
        $audit->record($request->user(), 'organization.updated', $organization);

        return new OrganizationResource($organization->fresh());
    }
}
