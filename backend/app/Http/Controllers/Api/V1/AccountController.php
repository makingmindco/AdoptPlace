<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AdoptionRequestStatus;
use App\Enums\PetStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdoptionRequestResource;
use App\Http\Resources\PetResource;
use App\Models\AdoptionRequest;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function favorites(Request $request)
    {
        return response()->json(['data' => array_values($request->user()->favorite_ids ?? [])]);
    }

    public function favoritePets(Request $request)
    {
        $favoriteIds = array_values($request->user()->favorite_ids ?? []);

        return PetResource::collection(Pet::with('organization')->whereIn('_id', $favoriteIds)
            ->where('status', 'published')->whereHas('organization', fn ($q) => $q->where('verification_status', 'approved'))->paginate(12));
    }

    public function favorite(Request $request, string $id)
    {
        $user = $request->user();
        $rawFavorites = $user->getAttributes()['favorite_ids'] ?? null;
        if (is_string($rawFavorites)) {
            User::whereKey($user->id)->update([
                'favorite_ids' => array_values($user->favorite_ids ?? []),
            ]);
        }

        if ($request->isMethod('put')) {
            $pet = Pet::findOrFail($id);
            abort_unless($pet->status->value === 'published' && $pet->organization?->isApproved(), 404);
            User::whereKey($user->id)->push('favorite_ids', $id, true);
        } else {
            User::whereKey($user->id)->pull('favorite_ids', $id);
        }

        return response()->noContent();
    }

    public function adoptions(Request $request)
    {
        return AdoptionRequestResource::collection(AdoptionRequest::with('pet.organization')->where('user_id', (string) $request->user()->id)->latest()->paginate(12));
    }

    public function update(Request $request, AdoptionRequest $adoptionRequest)
    {
        abort_unless((string) $adoptionRequest->user_id === (string) $request->user()->id, 403);
        $data = $request->validate(['action' => 'required|in:withdraw,confirm']);
        DB::connection('mongodb')->transaction(function () use ($request, $adoptionRequest, $data) {
            $current = $adoptionRequest->fresh();
            $status = $current->status->value;
            abort_if(in_array($status, ['completed', 'rejected', 'withdrawn']), 409, 'Solicitação encerrada.');
            if ($data['action'] === 'confirm') {
                abort_unless($status === AdoptionRequestStatus::Approved->value, 409, 'Aguarde a confirmação da organização.');
                abort_unless($current->organization?->isApproved(), 409, 'Organização indisponível.');
                $changed = Pet::whereKey($current->pet_id)
                    ->whereIn('status', [PetStatus::Published->value, PetStatus::AdoptionInProgress->value])
                    ->update(['status' => PetStatus::Adopted->value, 'adopted_at' => now()]);
                abort_unless($changed, 409, 'Este animal já não está disponível. Entre em contato com o responsável.');
            }
            $next = $data['action'] === 'confirm'
                ? AdoptionRequestStatus::Completed->value
                : AdoptionRequestStatus::Withdrawn->value;
            $history = $current->status_history ?? [];
            $historyEntry = [
                'status' => $next,
                'changed_at' => now()->toISOString(),
                'actor_id' => (string) $request->user()->id,
            ];
            if ($data['action'] === 'withdraw' && $status === AdoptionRequestStatus::Approved->value) {
                $historyEntry['reason'] = 'Desistência do adotante após aprovação; reserva liberada.';
            }
            $history[] = $historyEntry;
            $changed = AdoptionRequest::whereKey($current->id)->where('status', $status)->update(['status' => $next, 'status_history' => $history]);
            abort_unless($changed, 409, 'A solicitação mudou. Atualize a página.');

            if ($data['action'] === 'confirm') {
                AdoptionRequest::closeOpenForPet(
                    (string) $current->pet_id,
                    (string) $request->user()->id,
                    'Animal adotado por outro candidato confirmado.',
                    (string) $current->id,
                    true,
                );
            } elseif ($status === AdoptionRequestStatus::Approved->value) {
                if (! AdoptionRequest::hasApprovedForPet((string) $current->pet_id, (string) $current->id)) {
                    Pet::whereKey($current->pet_id)
                        ->where('status', PetStatus::AdoptionInProgress->value)
                        ->update(['status' => PetStatus::Published->value]);
                }
            }
        });

        return new AdoptionRequestResource($adoptionRequest->fresh()->load('pet.organization'));
    }

    public function review(Request $request, AdoptionRequest $adoptionRequest)
    {
        abort_unless((string) $adoptionRequest->user_id === (string) $request->user()->id, 403);
        abort_unless($adoptionRequest->status->value === 'completed', 403, 'Avalie após concluir a adoção.');
        $data = $request->validate(['rating' => 'required|integer|min:1|max:5', 'comment' => 'required|string|min:10|max:1500']);
        $data += ['status' => 'pending', 'created_at' => now()->toISOString()];
        $changed = AdoptionRequest::whereKey($adoptionRequest->id)->whereNull('review')->where('status', 'completed')->update(['review' => $data]);
        abort_unless($changed, 409, 'Você já avaliou esta adoção.');

        return response()->json(['message' => 'Avaliação enviada para moderação.'], 201);
    }
}
