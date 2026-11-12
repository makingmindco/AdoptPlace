<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Enums\PetStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PetResource;
use App\Models\Pet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use MongoDB\BSON\Regex;

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
            'featured' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        $baseQuery = Pet::query()
            ->with('organization')
            ->where('status', PetStatus::Published->value)
            ->whereHas('organization', fn ($query) => $query->where('verification_status', OrganizationStatus::Approved->value))
            ->when($validated['species'] ?? null, fn ($query, $value) => $query->where('species', $value))
            ->when($validated['size'] ?? null, fn ($query, $value) => $query->where('size', $value))
            ->when($validated['sex'] ?? null, fn ($query, $value) => $query->where('sex', $value))
            ->when($validated['city'] ?? null, function ($query, string $value) {
                $city = trim($value);
                $rawPattern = preg_quote($city, '/');
                $jsonCity = substr((string) json_encode($city), 1, -1);
                $jsonPattern = preg_quote($jsonCity, '/');
                $legacyPattern = $rawPattern === $jsonPattern
                    ? '"city"\s*:\s*"[^"]*'.$rawPattern.'[^"]*"'
                    : '"city"\s*:\s*"[^"]*(?:'.$rawPattern.'|'.$jsonPattern.')[^"]*"';

                $query->where(function ($subQuery) use ($rawPattern, $legacyPattern) {
                    $subQuery->where('location.city', 'regex', new Regex($rawPattern, 'i'))
                        ->orWhere('location', 'regex', new Regex($legacyPattern, 'i'));
                });
            })
            ->when($validated['state'] ?? null, function ($query, string $value) {
                $state = strtoupper(trim($value));
                $escaped = preg_quote($state, '/');

                $query->where(function ($subQuery) use ($state, $escaped) {
                    $subQuery->where('location.state', $state)
                        ->orWhere('location', 'regex', new Regex('"state"\s*:\s*"'.$escaped.'"', 'i'));
                });
            });

        if ($request->boolean('featured')) {
            $withPhotoQuery = (clone $baseQuery)
                ->whereNotNull('cover_photo')
                ->where('cover_photo', '!=', '');

            if ((clone $withPhotoQuery)->exists()) {
                $baseQuery = $withPhotoQuery;
            }
        }

        $pets = $baseQuery
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
