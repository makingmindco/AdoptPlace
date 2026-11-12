<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Resources\AdoptionRequestResource;
use App\Models\AdoptionRequest;
use App\Models\User;
use App\Policies\AdoptionRequestPolicy;
use Illuminate\Auth\Passwords\CacheTokenRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    private function user(string $role = 'adopter'): User
    {
        $user = new User(['name' => 'Ana', 'role' => $role, 'status' => 'active']);
        $user->id = 'user-one';

        return $user;
    }

    public function test_account_and_moderation_endpoints_require_authentication(): void
    {
        foreach (['/account/favorites', '/account/favorite-pets', '/account/adoptions', '/admin/reviews'] as $path) {
            $this->getJson('/api/v1'.$path)->assertUnauthorized();
        }
        $this->postJson('/api/v1/pets/unknown/adoption-requests')->assertUnauthorized();
    }

    public function test_adopters_cannot_moderate(): void
    {
        $this->actingAs($this->user())->getJson('/api/v1/admin/reviews')->assertForbidden();
        $this->getJson('/api/v1/admin/organizations')->assertForbidden();
    }

    public function test_password_reset_invalidates_previous_sessions(): void
    {
        $user = $this->user();
        $user->session_version = 'new-version';
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->actingAs($user)->withSession(['account_version' => 'old-version'])
            ->withHeader('Origin', 'http://localhost:5173')->getJson('/api/v1/account/favorites')->assertUnauthorized();
    }

    public function test_disabled_users_cannot_use_account_endpoints(): void
    {
        $user = $this->user();
        $user->status = 'suspended';
        $this->actingAs($user)->getJson('/api/v1/account/favorites')->assertUnauthorized();
    }

    public function test_favorites_are_scoped_to_authenticated_user(): void
    {
        $user = $this->user();
        $user->favorite_ids = ['pet-one'];
        $this->actingAs($user)->getJson('/api/v1/account/favorites')->assertOk()->assertExactJson(['data' => ['pet-one']]);
    }

    public function test_review_rejects_another_users_adoption_before_database_access(): void
    {
        $request = Request::create('/', 'POST', ['rating' => 5, 'comment' => 'Ótima experiência']);
        $request->setUserResolver(fn () => $this->user());
        $adoption = new AdoptionRequest(['user_id' => 'another-user', 'status' => 'completed']);
        try {
            (new AccountController)->review($request, $adoption);
            $this->fail('Unauthorized review accepted');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_review_requires_completed_adoption(): void
    {
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $this->user());
        $adoption = new AdoptionRequest(['user_id' => 'user-one', 'status' => 'approved']);
        try {
            (new AccountController)->review($request, $adoption);
            $this->fail('Premature review accepted');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_internal_notes_are_not_exposed_to_adopter(): void
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->user());
        $adoption = new AdoptionRequest(['user_id' => 'user-one', 'organization_id' => 'org-one', 'internal_notes' => 'Private', 'status' => 'new']);
        $data = (new AdoptionRequestResource($adoption))->resolve($request);
        $this->assertArrayNotHasKey('internal_notes', $data);
        $owner = $this->user('organization_owner');
        $owner->organization_id = 'org-one';
        $request->setUserResolver(fn () => $owner);
        $this->assertSame('Private', (new AdoptionRequestResource($adoption))->resolve($request)['internal_notes']);
    }

    public function test_organization_policy_rejects_unrelated_and_missing_organizations(): void
    {
        $policy = new AdoptionRequestPolicy;
        $this->assertFalse($policy->view($this->user(), new AdoptionRequest));
        $user = $this->user('organization_owner');
        $user->organization_id = 'org-one';
        $this->assertFalse($policy->update($user, new AdoptionRequest(['organization_id' => 'org-two'])));
        $this->assertTrue($policy->update($user, new AdoptionRequest(['organization_id' => 'org-one'])));
    }

    public function test_password_broker_uses_expiring_cache_tokens(): void
    {
        $this->assertInstanceOf(CacheTokenRepository::class, Password::broker()->getRepository());
        $user = $this->user();
        $user->email = 'ana@example.test';
        $repository = Password::broker()->getRepository();
        $token = $repository->create($user);
        $this->assertTrue($repository->exists($user, $token));
        $this->assertFalse($repository->exists($user, 'invalid-token'));
        $this->travel(61)->minutes();
        $this->assertFalse($repository->exists($user, $token));
    }

    public function test_password_recovery_does_not_disclose_account_existence(): void
    {
        Password::shouldReceive('sendResetLink')->once()->andReturn(Password::INVALID_USER);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk()
            ->assertJson(['message' => 'Se houver uma conta com esse e-mail, você receberá as instruções de recuperação.']);
    }

    public function test_reset_rejects_invalid_token_and_short_password(): void
    {
        Password::shouldReceive('reset')->once()->andReturn(Password::INVALID_TOKEN);
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'ana@example.test', 'token' => 'invalid', 'password' => 'Amigo#12', 'password_confirmation' => 'Amigo#12'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'ana@example.test', 'token' => 'invalid', 'password' => 'Ab1!', 'password_confirmation' => 'Ab1!'])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
