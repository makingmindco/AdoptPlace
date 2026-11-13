<?php

namespace App\Services\Photos;

use App\Contracts\PhotoStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class PhotoManager
{
    public function __construct(private PhotoStorage $storage) {}

    public function uploadAndPersist(UploadedFile $file, string $petId, callable $persist): mixed
    {
        try {
            $photo = $this->storage->upload($file, $petId);
        } catch (\Throwable) {
            Log::warning('Photo upload outcome could not be confirmed.', ['pet_id' => $petId]);
            abort(503, 'Não foi possível confirmar o envio da foto. Tente novamente; se persistir, contate o suporte.');
        }
        try {
            return $persist($photo);
        } catch (\Throwable $original) {
            for ($cause = $original; $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof \MongoDB\Driver\Exception\RuntimeException) {
                    $this->queueCleanup($photo, $petId, 'uncertain_persistence');
                    Log::error('Photo persistence outcome uncertain; manual reconciliation required.', ['photo_id' => $photo['id'], 'pet_id' => $petId]);
                    abort(503, 'Não foi possível confirmar a gravação da foto. Contate o suporte antes de tentar novamente.');
                }
            }
            try {
                $this->storage->delete($photo);
            } catch (\Throwable) {
                $this->queueCleanup($photo, $petId, 'failed_compensation');
                // No SDK exception text or credentials are written to logs.
                Log::error('Photo upload compensation failed; manual cleanup required.', [
                    'photo_id' => $photo['id'], 'pet_id' => $petId, 'provider' => $photo['provider'],
                ]);
                abort(503, 'A foto não foi salva e a limpeza não pôde ser confirmada. Contate o suporte.');
            }
            throw $original;
        }
    }

    protected function queueCleanup(array $photo, string $petId, string $reason): void
    {
        try {
            \App\Models\PhotoDeletion::firstOrCreate(
                ['id' => \App\Models\PhotoDeletion::identifier($petId, $photo['id'])],
                ['pet_id' => $petId, 'photo_id' => $photo['id'], 'photo' => $photo,
                    'status' => 'pending', 'reason' => $reason],
            );
        } catch (\Throwable) {
            Log::error('Photo cleanup could not be queued; manual inspection required.', ['photo_id' => $photo['id'], 'pet_id' => $petId]);
        }
    }
}
