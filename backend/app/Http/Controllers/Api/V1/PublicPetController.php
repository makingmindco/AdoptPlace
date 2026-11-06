<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PetStatus;
use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PetResource;
use App\Models\Pet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicPetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'species' => ['nullable', 'in:dog,cat,other'],
            'size' => ['nullable', 'in:small,medium,large'],
            'sex' => ['nullable', 'in:male,female,unknown'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'size:2'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        $pets = Pet::query()
            ->with('organization')
            ->where('status', PetStatus::Published->value)
            ->whereHas('organization', fn ($query) => $query->where('verification_status', OrganizationStatus::Approved->value))
            ->when($validated['species'] ?? null, fn ($query, $value) => $query->where('species', $value))
            ->when($validated['size'] ?? null, fn ($query, $value) => $query->where('size', $value))
            ->when($validated['sex'] ?? null, fn ($query, $value) => $query->where('sex', $value))
            ->when($validated['city'] ?? null, fn ($query, $value) => $query->where('location.city', $value))
            ->when($validated['state'] ?? null, fn ($query, $value) => $query->where('location.state', strtoupper($value)))
            ->latest('published_at')
            ->paginate($validated['per_page'] ?? 12)
            ->withQueryString();

        return PetResource::collection($pets);
    }

    public function show(string $slug): PetResource
    {
        $pet = Pet::with('organization')
            ->where('slug', $slug)
            ->where('status', PetStatus::Published->value)
            ->firstOrFail();

        abort_unless($pet->organization?->isApproved(), 404);

        return new PetResource($pet);
    }
}
