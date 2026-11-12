<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PetStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PetResource;
use App\Models\AdoptionRequest;
use App\Models\Pet;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PetResource::collection(
            Pet::with('organization')->latest()->paginate(min($request->integer('per_page', 20), 50))
        );
    }

    public function moderate(Request $request, Pet $pet, AuditService $audit): PetResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(PetStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetStatus = PetStatus::from($data['status']);

        DB::connection('mongodb')->transaction(function () use ($request, $pet, $targetStatus, $data) {
            $currentPet = $pet->fresh();
            $hasApproved = AdoptionRequest::hasApprovedForPet((string) $currentPet->id);

            if ($targetStatus === PetStatus::AdoptionInProgress) {
                abort_unless($hasApproved, 409, 'O status em processo de adoção exige uma solicitação aprovada ativa.');
            }

            if (in_array($targetStatus, [PetStatus::Published, PetStatus::Draft], true)) {
                abort_if($hasApproved, 409, 'Não é possível alterar para este status enquanto houver uma solicitação aprovada aguardando confirmação.');
            }

            if ($targetStatus === PetStatus::Adopted) {
                abort_if($hasApproved, 409, 'Existe uma solicitação aprovada para este animal. Aguarde a confirmação do adotante ou encerre a solicitação antes de marcar como adotado.');

                $currentPet->update([
                    'status' => PetStatus::Adopted,
                    'adopted_at' => $currentPet->adopted_at ?? now(),
                ]);

                AdoptionRequest::closeOpenForPet(
                    (string) $currentPet->id,
                    (string) $request->user()->id,
                    $data['reason'] ?? 'Animal marcado como adotado pela moderação.',
                );

                return;
            }

            if ($targetStatus === PetStatus::Archived) {
                $currentPet->update(['status' => PetStatus::Archived]);

                AdoptionRequest::closeOpenForPet(
                    (string) $currentPet->id,
                    (string) $request->user()->id,
                    $data['reason'] ?? 'Animal arquivado pela moderação.',
                    null,
                    true,
                );

                return;
            }

            $updates = ['status' => $targetStatus];
            if ($targetStatus === PetStatus::Published) {
                $updates['published_at'] = $currentPet->published_at ?? now();
                $updates['adopted_at'] = null;
            }

            $currentPet->update($updates);
        });

        $audit->record($request->user(), 'pet.moderated', $pet, $data);

        return new PetResource($pet->fresh()->load('organization'));
    }
}
