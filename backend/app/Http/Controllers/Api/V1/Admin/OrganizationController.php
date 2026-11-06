<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOrganizationStatusRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:pending,approved,rejected,suspended']]);

        return OrganizationResource::collection(
            Organization::query()
                ->when($request->input('status'), fn ($query, $status) => $query->where('verification_status', $status))
                ->latest()
                ->paginate(min($request->integer('per_page', 20), 50))
        );
    }

    public function updateStatus(
        UpdateOrganizationStatusRequest $request,
        Organization $organization,
        AuditService $audit,
    ): OrganizationResource {
        $status = OrganizationStatus::from($request->validated('status'));
        $organization->update([
            'verification_status' => $status,
            'verification_notes' => $request->validated('notes'),
            'approved_at' => $status === OrganizationStatus::Approved ? now() : null,
        ]);
        $audit->record($request->user(), 'organization.status_updated', $organization, ['status' => $status->value]);

        return new OrganizationResource($organization->fresh());
    }
}
