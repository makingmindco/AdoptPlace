<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $slug = $this->uniqueOrganizationSlug($data['name']);

        $organization = Organization::create([
            'name' => $data['name'],
            'slug' => $slug,
            'type' => $data['type'] ?? 'ngo',
            'city' => $data['city'],
            'state' => strtoupper($data['state'] ?? ''),
            'email' => strtolower($data['email']),
            'verification_status' => OrganizationStatus::Pending,
            'contact_publicly_visible' => false,
        ]);

        try {
            $user = User::create([
                'name' => $data['owner_name'] ?? $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'role' => UserRole::OrganizationOwner,
                'organization_id' => $organization->id,
                'status' => 'active',
            ]);
        } catch (\Throwable $exception) {
            $organization->delete();
            throw $exception;
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->sendEmailVerificationNotification();

        return (new UserResource($user->load('organization')))
            ->response()
            ->setStatusCode(201);
    }

    public function login(LoginRequest $request): UserResource
    {
        if (! Auth::attempt($request->validated(), true)) {
            throw ValidationException::withMessages([
                'email' => ['E-mail ou senha inválidos.'],
            ]);
        }

        $request->session()->regenerate();
        $user = $request->user();

        if ($user->status !== 'active') {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => ['Esta conta está indisponível.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return new UserResource($user->load('organization'));
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('organization'));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    private function uniqueOrganizationSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organizacao';
        $slug = $base;
        $suffix = 2;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
