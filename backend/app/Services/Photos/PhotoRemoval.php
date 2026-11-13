<?php

namespace App\Services\Photos;

use App\Enums\PetStatus;
use App\Models\Pet;
use App\Models\PhotoDeletion;
use Illuminate\Validation\ValidationException;

class PhotoRemoval
{
    public function __construct(private PhotoDeletionProcessor $processor) {}

    public function remove(Pet $pet, string $photoId, string $actorId): PhotoDeletion
    {
        // No provider operation runs inside this transaction. Only its confirmed
        // return permits the caller to start physical cleanup.
        return $pet->getConnection()->transaction(function () use ($pet, $photoId, $actorId) {
            $current = $pet->fresh();
            $taskId = PhotoDeletion::identifier((string) $current->id, $photoId);
            $existing = PhotoDeletion::find($taskId);
            if ($existing) {
                return $existing;
            }
            $photo = collect($current->photos ?? [])->firstWhere('id', $photoId);
            abort_unless($photo, 404);
            $remaining = array_values(array_filter($current->photos ?? [], fn ($item) => $item['id'] !== $photoId));
            if (! $remaining && in_array($current->status, [PetStatus::Published, PetStatus::AdoptionInProgress], true)) {
                throw ValidationException::withMessages(['photo' => ['Um animal publicado ou reservado precisa manter pelo menos uma foto.']]);
            }
            try {
                $photo = $this->processor->normalize($photo, (string) $current->id);
            } catch (\RuntimeException) {
                abort(409, 'Não foi possível identificar com segurança a origem desta foto. Contate o suporte.');
            }
            $current->update([
                'photos' => $remaining,
                'cover_photo' => $current->cover_photo === $photo['url'] ? ($remaining[0]['url'] ?? null) : $current->cover_photo,
            ]);
            $task = new PhotoDeletion;
            $task->id = $taskId; // MongoDB's built-in unique _id makes retries deterministic.
            $task->fill(['pet_id' => (string) $current->id, 'photo_id' => $photoId,
                'actor_id' => $actorId, 'photo' => $photo, 'status' => 'pending', 'reason' => 'user_removal']);
            $task->save();

            return $task;
        });
    }
}
