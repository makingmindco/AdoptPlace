<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePetRequest;
use App\Http\Requests\UpdatePetRequest;
use App\Http\Requests\UploadPetPhotoRequest;
use App\Http\Resources\PetResource;
use App\Models\Pet;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrganizationPetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $pets = Pet::where('organization_id', $request->user()->organization_id)
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 15), 50));

        return PetResource::collection($pets);
    }

    public function store(StorePetRequest $request, AuditService $audit): PetResource
    {
        $data = $request->validated();
        $data['organization_id'] = $request->user()->organization_id;
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['status'] = PetStatus::Draft;
        $data['photos'] = [];
        $data['location']['state'] = strtoupper($data['location']['state']);

        $pet = Pet::create($data);
        $audit->record($request->user(), 'pet.created', $pet);

        return new PetResource($pet->load('organization'));
    }

    public function show(Request $request, Pet $pet): PetResource
    {
        $this->authorize('view', $pet);

        return new PetResource($pet->load('organization'));
    }

    public function update(UpdatePetRequest $request, Pet $pet, AuditService $audit): PetResource
    {
        $this->authorize('update', $pet);
        $data = $request->validated();

        if (isset($data['location']['state'])) {
            $data['location']['state'] = strtoupper($data['location']['state']);
        }

        $pet->update($data);
        $audit->record($request->user(), 'pet.updated', $pet);

        return new PetResource($pet->fresh()->load('organization'));
    }

    public function destroy(Request $request, Pet $pet, AuditService $audit): JsonResponse
    {
        $this->authorize('delete', $pet);
        $pet->update(['status' => PetStatus::Archived]);
        $audit->record($request->user(), 'pet.archived', $pet);

        return response()->json(['message' => 'Animal arquivado.']);
    }

    public function publish(Request $request, Pet $pet, AuditService $audit): PetResource
    {
        $this->authorize('update', $pet);

        if (! $pet->cover_photo) {
            throw ValidationException::withMessages([
                'photo' => ['Adicione uma foto de capa antes de publicar.'],
            ]);
        }

        $pet->update([
            'status' => PetStatus::Published,
            'published_at' => $pet->published_at ?? now(),
            'adopted_at' => null,
        ]);
        $audit->record($request->user(), 'pet.published', $pet);

        return new PetResource($pet->fresh()->load('organization'));
    }

    public function markAdopted(Request $request, Pet $pet, AuditService $audit): PetResource
    {
        $this->authorize('update', $pet);
        $pet->update(['status' => PetStatus::Adopted, 'adopted_at' => now()]);
        $audit->record($request->user(), 'pet.adopted', $pet);

        return new PetResource($pet->fresh()->load('organization'));
    }

    public function uploadPhoto(UploadPetPhotoRequest $request, Pet $pet, AuditService $audit): PetResource
    {
        $this->authorize('update', $pet);

        $disk = config('filesystems.pet_media_disk', 'public');
        $path = $request->file('photo')->storePublicly('pets/'.$pet->id, $disk);

        if (! $path) {
            abort(500, 'Não foi possível armazenar a imagem.');
        }

        $photo = [
            'id' => (string) Str::uuid(),
            'path' => $path,
            'url' => Storage::disk($disk)->url($path),
        ];
        $photos = [...($pet->photos ?? []), $photo];
        $isCover = $request->boolean('is_cover') || ! $pet->cover_photo;

        $pet->update([
            'photos' => $photos,
            'cover_photo' => $isCover ? $photo['url'] : $pet->cover_photo,
        ]);
        $audit->record($request->user(), 'pet.photo_uploaded', $pet, ['photo_id' => $photo['id']]);

        return new PetResource($pet->fresh()->load('organization'));
    }

    public function deletePhoto(Request $request, Pet $pet, string $photoId, AuditService $audit): PetResource
    {
        $this->authorize('update', $pet);
        $photos = collect($pet->photos ?? []);
        $photo = $photos->firstWhere('id', $photoId);
        abort_unless($photo, 404);

        Storage::disk(config('filesystems.pet_media_disk', 'public'))->delete($photo['path']);
        $remaining = $photos->reject(fn ($item) => $item['id'] === $photoId)->values()->all();
        $cover = $pet->cover_photo === $photo['url'] ? ($remaining[0]['url'] ?? null) : $pet->cover_photo;
        $pet->update(['photos' => $remaining, 'cover_photo' => $cover]);
        $audit->record($request->user(), 'pet.photo_deleted', $pet, ['photo_id' => $photoId]);

        return new PetResource($pet->fresh()->load('organization'));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'animal';
        $slug = $base;
        $suffix = 2;

        while (Pet::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
