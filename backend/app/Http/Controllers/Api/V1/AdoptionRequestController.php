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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use MongoDB\Driver\Exception\BulkWriteException;

class AdoptionRequestController extends Controller
{
    public function store(StoreAdoptionRequest $request, Pet $pet): AdoptionRequestResource
    {
        abort_unless($pet->status === PetStatus::Published, 404);
        abort_unless($pet->organization?->isApproved(), 404);
        abort_if((string) $request->user()->organization_id === (string) $pet->organization_id, 403);
        abort_if(AdoptionRequest::where('user_id', (string) $request->user()->id)->where('pet_id', $pet->id)->exists(), 409, 'Você já enviou uma solicitação para este animal. Acompanhe em Minha conta.');
        $data = $request->validated();

        try {
            $adoptionRequest = AdoptionRequest::create([
                'pet_id' => $pet->id,
                'user_id' => (string) $request->user()->id,
                'organization_id' => $pet->organization_id,
                'adopter' => collect($data)->except('privacy_consent')->all(),
                'privacy_consent_at' => now(),
                'status' => AdoptionRequestStatus::New,
                'status_history' => [[
                    'status' => AdoptionRequestStatus::New->value,
                    'changed_at' => now()->toISOString(),
                    'actor_id' => (string) $request->user()->id,
                ]],
            ]);

        } catch (BulkWriteException $exception) {
            if ($exception->getCode() === 11000) {
                abort(409, 'Você já enviou uma solicitação para este animal.');
            }
            throw $exception;
        }
        $adoptionRequest->load('pet.organization.users');
        try {
            Notification::send($adoptionRequest->pet->organization->users, new AdoptionRequestReceived($adoptionRequest));
        } catch (\Throwable $exception) {
            Log::warning('Adoption notification delivery failed', ['request_id' => (string) $adoptionRequest->id]);
        }

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
        abort_if(in_array($adoptionRequest->status->value, ['completed', 'withdrawn', 'rejected']), 409, 'Esta solicitação já foi encerrada.');
        abort_unless(in_array($data['status'], ['reviewing', 'contacted', 'approved', 'rejected']), 422, 'Selecione uma etapa válida. A conclusão deve ser confirmada pelo adotante.');
        $history = $adoptionRequest->status_history ?? [];
        $history[] = [
            'status' => $data['status'],
            'changed_at' => now()->toISOString(),
            'actor_id' => (string) $request->user()->id,
        ];

        $changed = AdoptionRequest::whereKey($adoptionRequest->id)->where('status', $adoptionRequest->status->value)->update([
            'status' => $data['status'],
            'internal_notes' => $data['internal_notes'] ?? $adoptionRequest->internal_notes,
            'status_history' => $history,
        ]);
        abort_unless($changed, 409, 'A solicitação mudou. Atualize a página.');
        $audit->record($request->user(), 'adoption_request.updated', $adoptionRequest, ['status' => $data['status']]);

        return new AdoptionRequestResource($adoptionRequest->fresh()->load('pet'));
    }
}
