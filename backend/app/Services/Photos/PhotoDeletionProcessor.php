<?php

namespace App\Services\Photos;

use App\Models\Pet;
use App\Models\PhotoDeletion;
use Illuminate\Support\Facades\Log;

class PhotoDeletionProcessor
{
    public function normalize(array $photo, string $petId): array
    {
        if (empty($photo['provider']) && empty($photo['path']) && preg_match('~^/demo-pets/[a-z0-9-]+\.png$~', $photo['url'] ?? '')) {
            return [...$photo, 'provider' => 'demo'];
        }
        $provider = $photo['provider'] ?? 'storage';
        $field = $provider === 'cloudinary' ? 'public_id' : 'path';
        $prefix = $provider === 'cloudinary' ? 'adoptplace/pets/'.$petId.'/' : 'pets/'.$petId.'/';
        $value = $photo[$field] ?? null;
        if (! in_array($provider, ['storage', 'cloudinary'], true) || ! is_string($value)
            || ! str_starts_with($value, $prefix) || strlen($value) <= strlen($prefix)
            || str_contains($value, '..') || str_contains($value, '\\')) {
            throw new \RuntimeException('Photo ownership cannot be established.');
        }
        $photo['provider'] = $provider;
        if ($provider === 'storage') {
            // Legacy uploads used public by default. Never infer from the current provider.
            $photo['disk'] ??= config('photos.legacy_disk', 'public');
            if (! array_key_exists($photo['disk'], config('filesystems.disks', []))) {
                throw new \RuntimeException('Unknown photo storage disk.');
            }
        }

        return $photo;
    }

    protected function referenced(PhotoDeletion $task): bool
    {
        $pet = Pet::find($task->pet_id);
        foreach ($pet?->photos ?? [] as $photo) {
            if (($photo['id'] ?? null) === $task->photo_id
                || (! empty($photo['url']) && $photo['url'] === ($task->photo['url'] ?? null))
                || (! empty($photo['path']) && $photo['path'] === ($task->photo['path'] ?? null))
                || (! empty($photo['public_id']) && $photo['public_id'] === ($task->photo['public_id'] ?? null))) {
                return true;
            }
        }

        return $pet && ! empty($pet->cover_photo) && $pet->cover_photo === ($task->photo['url'] ?? null);
    }

    protected function record(PhotoDeletion $task, array $changes): void
    {
        PhotoDeletion::whereKey($task->id)->where('status', '!=', 'completed')->update($changes);
    }

    public function process(PhotoDeletion $task): bool
    {
        if ($task->status === 'completed') {
            return true;
        }
        try {
            $photo = $this->normalize($task->photo, (string) $task->pet_id);
            if ($this->referenced($task)) {
                $this->record($task, ['status' => 'blocked', 'last_error' => 'still_referenced']);
                return false;
            }
            match ($photo['provider']) {
                'demo' => null,
                'storage' => app(LocalPhotoStorage::class)->delete($photo),
                'cloudinary' => app(CloudinaryPhotoStorage::class)->delete($photo),
            };
            $this->record($task, ['status' => 'completed', 'last_error' => null, 'completed_at' => now()->toISOString()]);
            return true;
        } catch (\Throwable) {
            // Deletion may have succeeded before a timeout. Retrying is idempotent.
            try {
                $this->record($task, ['status' => 'failed', 'last_error' => 'cleanup_not_confirmed', 'last_attempt_at' => now()->toISOString()]);
            } catch (\Throwable) {
                Log::error('Unable to record photo cleanup outcome.', ['task_id' => $task->id]);
            }
            return false;
        }
    }
}
