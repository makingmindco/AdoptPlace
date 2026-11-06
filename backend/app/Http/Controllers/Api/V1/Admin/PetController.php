<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PetStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PetResource;
use App\Models\Pet;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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

        $pet->update(['status' => $data['status']]);
        $audit->record($request->user(), 'pet.moderated', $pet, $data);

        return new PetResource($pet->fresh()->load('organization'));
    }
}
