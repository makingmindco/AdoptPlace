<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePetRequest;
use App\Http\Requests\UpdatePetRequest;
use App\Http\Requests\UploadPetPhotoRequest;
use App\Http\Resources\PetResource;
use App\Models\AdoptionRequest;
use App\Models\Pet;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\Regex;

class OrganizationPetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:draft,published,adoption_in_progress,adopted,archived'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $organizationId = $request->user()->organization_id;

        $pets = Pet::where('organization_id', $organizationId)
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, function ($query, string $search) {
                $pattern = preg_quote(trim($search), '/');
                $query->where('name', 'regex', new Regex($pattern, 'i'));
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        $counts = [
            'total' => Pet::where('organization_id', $organizationId)->count(),
            'published' => Pet::where('organization_id', $organizationId)->where('status', PetStatus::Published->value)->count(),
            'draft' => Pet::where('organization_id', $organizationId)->where('status', PetStatus::Draft->value)->count(),
            'adoption_in_progress' => Pet::where('organization_id', $organizationId)->where('status', PetStatus::AdoptionInProgress->value)->count(),
            'adopted' => Pet::where('organization_id', $organizationId)->where('status', PetStatus::Adopted->value)->count(),
            'archived' => Pet::where('organization_id', $organizationId)->where('status', PetStatus::Archived->value)->count(),
        ];

        return PetResource::collection($pets)->additional(['counts' => $counts]);
    }

    public function store(StorePetRequest $request, AuditService $audit): PetResource
    {
        $data = $request->validated();
        $data['organization_id'] = $request->user()->organization_id;
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['status'] = PetStatus::Draft;
        $data['photos'] = [];
        $data['species'] = $data['species'] ?? 'dog';
        $data['sex'] = $data['sex'] ?? 'unknown';
        $data['size'] = $data['size'] ?? 'medium';

        if (! empty($data['location']['state'])) {
            $data['location']['state'] = strtoupper($data['location']['state']);
        }

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

        if (! empty($data['location']['state'])) {
            $data['location']['state'] = strtoupper($data['location']['state']);
        }

        if (
            array_key_exists('name', $data)
            && filled($data['name'])
            && $pet->status === PetStatus::Draft
            && $pet->published_at === null
            && trim((string) $data['name']) !== trim((string) $pet->name)
        ) {
            $data['slug'] = $this->uniqueSlug($data['name'], (string) $pet->id);
        }

        $pet->update($data);
        $audit->record($request->user(), 'pet.updated', $pet);

        return new PetResource($pet->fresh()->load('organization'));
    }

    public function destroy(Request $request, Pet $pet, AuditService $audit): JsonResponse
    {
        $this->authorize('delete', $pet);

        DB::connection('mongodb')->transaction(function () use ($request, $pet) {
            $currentPet = $pet->fresh();
            abort_if(
                $currentPet->status === PetStatus::AdoptionInProgress || AdoptionRequest::hasApprovedForPet((string) $currentPet->id),
                409,
                'Existe uma solicitação aprovada para este animal. Aguarde a confirmação do adotante ou libere a reserva antes de arquivar.'
            );

            $currentPet->update(['status' => PetStatus::Archived]);

            AdoptionRequest::closeOpenForPet(
                (string) $currentPet->id,
                (string) $request->user()->id,
                'Animal arquivado pela organização.',
            );
        });

        $audit->record($request->user(), 'pet.archived', $pet);

        return response()->json(['message' => 'Animal arquivado.']);
    }

    public function publish(Request $request, Pet $pet, AuditService $audit): PetResource
    {
        abort_unless($request->user()->hasVerifiedEmail(), 403, 'Confirme seu e-mail em Minha conta antes de publicar animais.');
        $this->authorize('update', $pet);

        abort_if(
            $pet->status === PetStatus::AdoptionInProgress || AdoptionRequest::hasApprovedForPet((string) $pet->id),
            409,
            'Não é possível republicar este animal enquanto houver uma solicitação aprovada aguardando confirmação.'
        );

        $coverPhoto = $pet->cover_photo ?: ($pet->photos[0]['url'] ?? null);
        $errors = [];

        if (! filled($pet->name)) {
            $errors['name'] = ['Informe o nome do animal antes de publicar.'];
        }
        if (! in_array($pet->species, ['dog', 'cat', 'other'], true)) {
            $errors['species'] = ['Selecione a espécie do animal antes de publicar.'];
        }
        if (! in_array($pet->sex, ['male', 'female', 'unknown'], true)) {
            $errors['sex'] = ['Selecione o sexo do animal antes de publicar.'];
        }
        if (! in_array($pet->size, ['small', 'medium', 'large'], true)) {
            $errors['size'] = ['Selecione o porte do animal antes de publicar.'];
        }
        if (mb_strlen(trim((string) $pet->description)) < 30) {
            $errors['description'] = ['A descrição deve ter pelo menos 30 caracteres antes da publicação.'];
        }
        if (! filled(data_get($pet->location, 'city'))) {
            $errors['location.city'] = ['Informe a cidade do animal antes de publicar.'];
        }
        if (mb_strlen(trim((string) data_get($pet->location, 'state'))) !== 2) {
            $errors['location.state'] = ['Informe o estado (UF com 2 letras) antes de publicar.'];
        }
        if (! filled($coverPhoto)) {
            $errors['photo'] = ['Adicione uma foto de capa antes de publicar.'];
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        DB::connection('mongodb')->transaction(function () use ($pet, $coverPhoto) {
            $currentPet = $pet->fresh();
            abort_if(
                $currentPet->status === PetStatus::AdoptionInProgress || AdoptionRequest::hasApprovedForPet((string) $currentPet->id),
                409,
                'Não é possível republicar este animal enquanto houver uma solicitação aprovada aguardando confirmação.'
            );

            $currentPet->update([
                'status' => PetStatus::Published,
                'cover_photo' => $currentPet->cover_photo ?: $coverPhoto,
                'published_at' => $currentPet->published_at ?? now(),
                'adopted_at' => null,
            ]);
        });

        $audit->record($request->user(), 'pet.published', $pet);

        return new PetResource($pet->fresh()->load('organization'));
    }

    public function markAdopted(Request $request, Pet $pet, AuditService $audit): PetResource
    {
        $this->authorize('update', $pet);
        $closedRequests = 0;

        DB::connection('mongodb')->transaction(function () use ($request, $pet, &$closedRequests) {
            $currentPet = $pet->fresh();
            abort_if($currentPet->status === PetStatus::Adopted, 409, 'Este animal já está marcado como adotado.');
            abort_if($currentPet->status === PetStatus::Archived, 409, 'Não é possível marcar um animal arquivado como adotado.');
            abort_if(
                $currentPet->status === PetStatus::AdoptionInProgress || AdoptionRequest::hasApprovedForPet((string) $currentPet->id),
                409,
                'Existe uma solicitação aprovada para este animal. Aguarde a confirmação do recebimento pelo adotante.'
            );

            $currentPet->update(['status' => PetStatus::Adopted, 'adopted_at' => now()]);

            $closedRequests = AdoptionRequest::closeOpenForPet(
                (string) $currentPet->id,
                (string) $request->user()->id,
                'Animal adotado externamente fora da plataforma.',
            );
        });

        $audit->record($request->user(), 'pet.adopted', $pet, ['source' => 'external', 'closed_requests' => $closedRequests]);

        return new PetResource($pet->fresh()->load('organization'));
    }

    public function uploadPhoto(UploadPetPhotoRequest $request, Pet $pet, AuditService $audit): PetResource
    {
        $this->authorize('update', $pet);

        if (count($pet->photos ?? []) >= 10) {
            throw ValidationException::withMessages([
                'photo' => ['O limite máximo é de 10 fotos por animal. Remova uma foto antes de enviar outra.'],
            ]);
        }

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

    public function setCoverPhoto(Request $request, Pet $pet, string $photoId, AuditService $audit): PetResource
    {
        $this->authorize('update', $pet);
        $photos = collect($pet->photos ?? []);
        $photo = $photos->firstWhere('id', $photoId);
        abort_unless($photo && ! empty($photo['url']), 404);

        $pet->update(['cover_photo' => $photo['url']]);
        $audit->record($request->user(), 'pet.cover_photo_updated', $pet, ['photo_id' => $photoId]);

        return new PetResource($pet->fresh()->load('organization'));
    }

    public function deletePhoto(Request $request, Pet $pet, string $photoId, AuditService $audit): PetResource
    {
        $this->authorize('update', $pet);
        $photos = collect($pet->photos ?? []);
        $photo = $photos->firstWhere('id', $photoId);
        abort_unless($photo, 404);

        $remaining = $photos->reject(fn ($item) => $item['id'] === $photoId)->values()->all();
        if (empty($remaining) && in_array($pet->status, [PetStatus::Published, PetStatus::AdoptionInProgress], true)) {
            throw ValidationException::withMessages([
                'photo' => ['Um animal publicado ou reservado precisa manter pelo menos uma foto. Adicione outra foto antes de remover a atual.'],
            ]);
        }

        if (! empty($photo['path'])) {
            Storage::disk(config('filesystems.pet_media_disk', 'public'))->delete($photo['path']);
        }

        $cover = $pet->cover_photo === $photo['url'] ? ($remaining[0]['url'] ?? null) : $pet->cover_photo;
        $pet->update(['photos' => $remaining, 'cover_photo' => $cover]);
        $audit->record($request->user(), 'pet.photo_deleted', $pet, ['photo_id' => $photoId]);

        return new PetResource($pet->fresh()->load('organization'));
    }

    private function uniqueSlug(string $name, ?string $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'animal';
        $slug = $base;
        $suffix = 2;

        while (
            Pet::where('slug', $slug)
                ->get(['_id'])
                ->contains(fn (Pet $existing) => (string) $existing->id !== (string) $ignoreId)
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
