<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AdoptionRequestStatus;
use App\Enums\PetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdoptionRequest;
use App\Http\Requests\UpdateAdoptionRequest;
use App\Http\Resources\AdoptionRequestResource;
use App\Models\AdoptionRequest;
use App\Models\Pet;
use App\Notifications\AdoptionRequestReceived;
use App\Services\AuditService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Notification;

class AdoptionRequestController extends Controller
{
    public function store(StoreAdoptionRequest $request, Pet $pet): AdoptionRequestResource
    {
        abort_unless($pet->status === PetStatus::Published, 404);
        $data = $request->validated();

        $adoptionRequest = AdoptionRequest::create([
            'pet_id' => $pet->id,
            'organization_id' => $pet->organization_id,
            'adopter' => collect($data)->except('privacy_consent')->all(),
            'privacy_consent_at' => now(),
            'status' => AdoptionRequestStatus::New,
            'status_history' => [[
                'status' => AdoptionRequestStatus::New->value,
                'changed_at' => now()->toISOString(),
                'actor_id' => null,
            ]],
        ]);

        $adoptionRequest->load('pet.organization.users');
        Notification::send($adoptionRequest->pet->organization->users, new AdoptionRequestReceived($adoptionRequest));

        return new AdoptionRequestResource($adoptionRequest);
    }

    public function index(): AnonymousResourceCollection
    {
        $requests = AdoptionRequest::with('pet')
            ->where('organization_id', request()->user()->organization_id)
            ->latest()
            ->paginate(min(request()->integer('per_page', 15), 50));

        return AdoptionRequestResource::collection($requests);
    }

    public function show(AdoptionRequest $adoptionRequest): AdoptionRequestResource
    {
        $this->authorize('view', $adoptionRequest);

        return new AdoptionRequestResource($adoptionRequest->load('pet'));
    }

    public function update(
        UpdateAdoptionRequest $request,
        AdoptionRequest $adoptionRequest,
        AuditService $audit,
    ): AdoptionRequestResource {
        $this->authorize('update', $adoptionRequest);
        $data = $request->validated();
        $history = $adoptionRequest->status_history ?? [];
        $history[] = [
            'status' => $data['status'],
            'changed_at' => now()->toISOString(),
            'actor_id' => (string) $request->user()->id,
        ];

        $adoptionRequest->update([
            'status' => $data['status'],
            'internal_notes' => $data['internal_notes'] ?? $adoptionRequest->internal_notes,
            'status_history' => $history,
        ]);
        $audit->record($request->user(), 'adoption_request.updated', $adoptionRequest, ['status' => $data['status']]);

        return new AdoptionRequestResource($adoptionRequest->fresh()->load('pet'));
    }
}
