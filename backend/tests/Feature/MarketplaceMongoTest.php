<?php

namespace Tests\Feature;

use App\Models\AdoptionRequest;
use App\Models\Organization;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use MongoDB\Client;
use Tests\TestCase;

class MarketplaceMongoTest extends TestCase
{
    private ?string $testDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_MONGODB_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set RUN_MONGODB_INTEGRATION=1 to use a disposable MongoDB database.');
        }
        $this->testDatabase = 'adoptplace_test_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 16);
        config(['database.connections.mongodb.database' => $this->testDatabase]);
        DB::purge('mongodb');
        Notification::fake();
        $this->artisan('mongodb:ensure-indexes')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->testDatabase && preg_match('/^adoptplace_test_[a-f0-9]{16}$/', $this->testDatabase)) {
                $client = new Client(config('database.connections.mongodb.dsn'));
                $client->dropDatabase($this->testDatabase);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_marketplace_persistence_permissions_transactions_and_password_reset(): void
    {
        $org = Organization::create(['name' => 'Test NGO', 'slug' => 'test-ngo', 'type' => 'ngo', 'city' => 'São Paulo', 'state' => 'SP', 'verification_status' => 'approved']);
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Amigo#12', 'role' => 'organization_owner', 'organization_id' => $org->id, 'status' => 'active']);
        $adopter = User::create(['name' => 'Adopter', 'email' => 'adopter@example.test', 'password' => 'Amigo#12', 'role' => 'adopter', 'status' => 'active']);
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'Amigo#12', 'role' => 'adopter', 'status' => 'active']);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'Amigo#12', 'role' => 'admin', 'status' => 'active']);
        $pet = Pet::create(['name' => 'Luna', 'slug' => 'luna-test', 'organization_id' => $org->id, 'status' => 'published', 'species' => 'cat', 'sex' => 'female', 'size' => 'small']);
        $this->actingAs($adopter)->putJson('/api/v1/account/favorites/'.$pet->id)->assertNoContent();
        $this->putJson('/api/v1/account/favorites/'.$pet->id)->assertNoContent();
        $this->actingAs($adopter->fresh())->getJson('/api/v1/account/favorites')->assertExactJson(['data' => [(string) $pet->id]]);
        $this->getJson('/api/v1/account/favorite-pets')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($other)->getJson('/api/v1/account/favorites')->assertExactJson(['data' => []]);
        $payload = ['name' => 'Adopter', 'email' => 'adopter@example.test', 'phone' => '11999999999', 'city' => 'São Paulo', 'state' => 'SP', 'housing_type' => 'house', 'has_other_pets' => false, 'experience' => 'Já cuidei de animais.', 'message' => 'Quero oferecer um lar responsável para este animal.', 'privacy_consent' => true];
        $response = $this->actingAs($adopter)->postJson('/api/v1/pets/'.$pet->id.'/adoption-requests', $payload)->assertSuccessful();
        $id = $response->json('data.id');
        $this->postJson('/api/v1/pets/'.$pet->id.'/adoption-requests', $payload)->assertConflict();
        $this->getJson('/api/v1/account/adoptions')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAs($other)->patchJson('/api/v1/account/adoptions/'.$id, ['action' => 'confirm'])->assertForbidden();
        $this->actingAs($owner)->patchJson('/api/v1/organization/adoption-requests/'.$id, ['status' => 'approved', 'internal_notes' => 'Internal only'])->assertOk();
        $this->actingAs($adopter)->getJson('/api/v1/account/adoptions')->assertJsonMissingPath('data.0.internal_notes');
        $this->postJson('/api/v1/account/adoptions/'.$id.'/review', ['rating' => 5, 'comment' => 'Ótima experiência com a organização.'])->assertForbidden();
        $this->patchJson('/api/v1/account/adoptions/'.$id, ['action' => 'confirm'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame('adopted', $pet->fresh()->status->value);
        $this->patchJson('/api/v1/account/adoptions/'.$id, ['action' => 'confirm'])->assertConflict();
        $this->getJson('/api/v1/account/favorite-pets')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/account/adoptions/'.$id.'/review', ['rating' => 5, 'comment' => 'Ótima experiência com a organização.'])->assertCreated();
        $this->postJson('/api/v1/account/adoptions/'.$id.'/review', ['rating' => 1, 'comment' => 'Avaliação duplicada.'])->assertConflict();
        $this->getJson('/api/v1/organizations/test-ngo/reviews')->assertJsonPath('count', 0);
        $this->actingAs($admin)->patchJson('/api/v1/admin/reviews/'.$id, ['status' => 'published'])->assertOk();
        $this->getJson('/api/v1/organizations/test-ngo/reviews')->assertOk()->assertJsonPath('count', 1)->assertJsonPath('reviews.data.0.rating', 5);
        $this->assertSame('published', AdoptionRequest::find($id)->review['status']);
        $token = Password::broker()->createToken($adopter);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $adopter->email, 'token' => $token, 'password' => 'Novo#123', 'password_confirmation' => 'Novo#123'])->assertOk();
        $this->assertTrue(Hash::check('Novo#123', $adopter->fresh()->password));
        $this->assertNotEmpty($adopter->fresh()->session_version);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $adopter->email, 'token' => $token, 'password' => 'Novo#456', 'password_confirmation' => 'Novo#456'])->assertUnprocessable();
    }
}
