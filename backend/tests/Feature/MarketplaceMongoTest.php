<?php

namespace Tests\Feature;

use App\Models\AdoptionRequest;
use App\Models\Organization;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MongoDB\Client;
use Tests\TestCase;

class MarketplaceMongoTest extends TestCase
{
    public function test_runtime_sessions_cache_and_reset_tokens_survive_driver_recreation(): void
    {
        config(['session.driver' => 'mongodb', 'session.lottery' => [0, 100]]);
        $user = User::create(['name' => 'Runtime fixture', 'email' => 'runtime@example.test', 'password' => 'Runtime#123', 'role' => 'adopter', 'status' => 'active']);
        $manager = $this->app['session'];
        $session = $manager->driver('mongodb');
        $session->start();
        $guardKey = $this->app['auth']->guard('web')->getName();
        $session->put($guardKey, (string) $user->id);
        $session->regenerateToken();
        $id = $session->getId();
        $csrf = $session->token();
        $session->save();
        $manager->forgetDrivers();
        $restored = $manager->driver('mongodb');
        $restored->setId($id);
        $restored->start();
        $this->assertSame((string) $user->id, $restored->get($guardKey));
        $guard = new \Illuminate\Auth\SessionGuard('web', $this->app['auth']->createUserProvider(config('auth.guards.web.provider')), $restored);
        $this->assertSame((string) $user->id, (string) $guard->user()->id);
        $this->assertSame($csrf, $restored->token());
        $restored->invalidate();
        $restored->regenerateToken();
        $restored->save();
        $this->assertSame(false, $restored->getHandler()->read($id));
        $this->assertNotSame($csrf, $restored->token());

        $connection = DB::connection('mongodb');
        $collection = config('cache.stores.mongodb.collection');
        $store = new \MongoDB\Laravel\Cache\MongoStore($connection, $collection);
        $store->put('persisted', 'value', 120);
        $freshStore = new \MongoDB\Laravel\Cache\MongoStore($connection, $collection);
        $this->assertSame('value', $freshStore->get('persisted'));
        $connection->getCollection($collection)->updateMany([], ['$set' => ['expires_at' => new \MongoDB\BSON\UTCDateTime((time() - 1) * 1000)]]);
        $this->assertNull($freshStore->get('persisted'));

        $passwordCollection = config('cache.stores.mongodb_passwords.collection');
        $tokens = new \Illuminate\Auth\Passwords\CacheTokenRepository(new \Illuminate\Cache\Repository(new \MongoDB\Laravel\Cache\MongoStore($connection, $passwordCollection)), app('hash'), config('app.key'), 3600, 60);
        $token = $tokens->create($user);
        $freshTokens = new \Illuminate\Auth\Passwords\CacheTokenRepository(new \Illuminate\Cache\Repository(new \MongoDB\Laravel\Cache\MongoStore($connection, $passwordCollection)), app('hash'), config('app.key'), 3600, 60);
        $this->assertTrue($freshTokens->exists($user, $token));
        $this->assertTrue($freshTokens->recentlyCreatedToken($user));
        $freshTokens->delete($user);
        $this->assertFalse($freshTokens->exists($user, $token));

        $session->getHandler()->write($id, 'expired fixture');
        $connection->getCollection(config('session.table'))->updateOne(['_id' => $id], ['$set' => ['expires_at' => new \MongoDB\BSON\UTCDateTime((time() - 1) * 1000)]]);
        $this->assertFalse($session->getHandler()->read($id));
    }

    public function test_photo_removal_rolls_back_metadata_if_pending_record_fails(): void
    {
        $pet = Pet::create(['name' => 'Rollback photo', 'status' => 'draft', 'photos' => [
            ['id' => 'demo-one', 'path' => null, 'url' => '/demo-pets/luna.png'],
        ], 'cover_photo' => '/demo-pets/luna.png']);
        $event = 'eloquent.creating: '.\App\Models\PhotoDeletion::class;
        \Illuminate\Support\Facades\Event::listen($event, fn () => throw new \RuntimeException('forced journal failure'));
        try {
            app(\App\Services\Photos\PhotoRemoval::class)->remove($pet, 'demo-one', 'actor');
            $this->fail('Journal failure should roll back');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced journal failure', $e->getMessage());
        } finally {
            \Illuminate\Support\Facades\Event::forget($event);
        }
        $this->assertCount(1, $pet->fresh()->photos);
        $this->assertSame('/demo-pets/luna.png', $pet->fresh()->cover_photo);
        $this->assertSame(0, \App\Models\PhotoDeletion::where('pet_id', (string) $pet->id)->count());
    }

    public function test_overlapping_photo_snapshots_cannot_both_commit_an_eleventh_photo(): void
    {
        // Real two-session snapshot conflict, in the disposable database only.
        $client = new Client(config('database.connections.mongodb.dsn'));
        $collection = $client->selectCollection($this->testDatabase, 'pets');
        $photos = array_fill(0, 9, ['id' => 'existing', 'url' => '/demo-pets/luna.png']);
        $collection->insertOne(['_id' => 'concurrent-photo-test', 'photos' => $photos]);
        $a = $client->startSession();
        $b = $client->startSession();
        try {
            $a->startTransaction();
            $b->startTransaction();
            $collection->findOne(['_id' => 'concurrent-photo-test'], ['session' => $a]);
            $collection->findOne(['_id' => 'concurrent-photo-test'], ['session' => $b]);
            $collection->updateOne(['_id' => 'concurrent-photo-test'], ['$push' => ['photos' => ['id' => 'a']]], ['session' => $a]);
            $a->commitTransaction();
            try {
                $collection->updateOne(['_id' => 'concurrent-photo-test'], ['$push' => ['photos' => ['id' => 'b']]], ['session' => $b]);
                $b->commitTransaction();
                $this->fail('Conflicting snapshot must not commit');
            } catch (\MongoDB\Driver\Exception\RuntimeException $e) {
                $this->assertTrue($e->getCode() === 112 || $e->hasErrorLabel('TransientTransactionError'));
            }
        } finally {
            if ($a->isInTransaction()) { $a->abortTransaction(); }
            if ($b->isInTransaction()) { $b->abortTransaction(); }
            $a->endSession();
            $b->endSession();
        }
        $this->assertCount(10, $collection->findOne(['_id' => 'concurrent-photo-test'])->photos);
    }

    private ?string $testDatabase = null;
    private ?Client $isolatedClient = null;
    private ?string $ownership = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_MONGODB_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set RUN_MONGODB_INTEGRATION=1 to use a disposable MongoDB database.');
        }
        $settings = [];
        foreach (['TEST_MONGODB_URI', 'TEST_MONGODB_DATABASE', 'TEST_MONGODB_CONFIRM_DATABASE', 'TEST_MONGODB_ISOLATED'] as $key) {
            $settings[$key] = getenv($key) ?: '';
        }
        [$uri, $name] = \Tests\Support\MongoIntegrationGuard::validate($settings);
        $this->isolatedClient = new Client($uri);
        $database = $this->isolatedClient->selectDatabase($name);
        $this->assertSame([], iterator_to_array($database->listCollectionNames()), 'Refusing to reuse a database containing any collection.');
        $nonce = bin2hex(random_bytes(24));
        $database->selectCollection('_test_ownership')->insertOne(['_id' => 'owner', 'nonce' => $nonce]);
        $this->testDatabase = $name;
        $this->ownership = $nonce;
        config(['database.connections.mongodb.database' => $name, 'database.connections.mongodb.dsn' => $uri,
            'photos.provider' => 'local', 'filesystems.pet_media_disk' => 'public',
            'session.driver' => 'array', 'cache.default' => 'array', 'auth.passwords.users.store' => 'array']);
        DB::purge('mongodb');
        Notification::fake();
        Storage::fake('public');
        $cloudinary = \Mockery::mock(\App\Contracts\CloudinaryClient::class);
        $cloudinary->shouldNotReceive('upload');
        $cloudinary->shouldNotReceive('destroy');
        $this->app->instance(\App\Contracts\CloudinaryClient::class, $cloudinary);
        $this->artisan('mongodb:ensure-indexes')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->ownership && $this->testDatabase && $this->isolatedClient) {
                $marker = $this->isolatedClient->selectCollection($this->testDatabase, '_test_ownership')->findOne(['_id' => 'owner']);
                if (($marker['nonce'] ?? null) !== $this->ownership) {
                    throw new \RuntimeException('Ownership mismatch: automatic test database cleanup refused.');
                }
                if ($this->testDatabase !== \Tests\Support\MongoIntegrationGuard::DATABASE) {
                    throw new \RuntimeException('Unexpected test database: cleanup refused.');
                }
                $database = $this->isolatedClient->selectDatabase($this->testDatabase);
                $collections = \Tests\Support\MongoIntegrationGuard::cleanupCollections(iterator_to_array($database->listCollectionNames()));
                foreach ($collections as $collection) {
                    $marker = $database->selectCollection('_test_ownership')->findOne(['_id' => 'owner']);
                    if (($marker['nonce'] ?? null) !== $this->ownership) {
                        throw new \RuntimeException('Ownership changed: cleanup stopped.');
                    }
                    $database->dropCollection($collection);
                }
                // Marker is removed last, only after all authorized collections.
                $database->dropCollection('_test_ownership');
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

    public function test_pet_arrays_persist_as_native_bson_and_filters_match_native_and_legacy_documents(): void
    {
        $org = Organization::create([
            'name' => 'ONG Porto Ferreira',
            'slug' => 'ong-porto-ferreira',
            'type' => 'ngo',
            'city' => 'Porto Ferreira',
            'state' => 'SP',
            'verification_status' => 'approved',
        ]);

        $nativePet = Pet::create([
            'name' => 'Bento',
            'slug' => 'bento-nativo',
            'organization_id' => $org->id,
            'status' => 'published',
            'species' => 'dog',
            'sex' => 'male',
            'size' => 'small',
            'description' => 'Um cachorrinho muito esperto e companheiro.',
            'location' => ['city' => 'Porto Ferreira', 'state' => 'SP'],
            'cover_photo' => '/demo-pets/bento.png',
            'photos' => [['id' => 'p-1', 'url' => '/demo-pets/bento.png', 'path' => null]],
            'health' => ['vaccinated' => true, 'neutered' => true],
            'personality' => ['esperto', 'amigável'],
            'published_at' => now(),
        ]);

        $rawNative = DB::connection('mongodb')->getCollection('pets')->findOne(['slug' => 'bento-nativo']);
        $this->assertIsObject($rawNative['location']);
        $this->assertIsObject($rawNative['photos']);
        $this->assertIsObject($rawNative['health']);
        $this->assertIsObject($rawNative['personality']);

        DB::connection('mongodb')->getCollection('pets')->insertOne([
            'organization_id' => $org->id,
            'name' => 'Luna Legada',
            'slug' => 'luna-legada',
            'species' => 'dog',
            'sex' => 'female',
            'size' => 'medium',
            'status' => 'published',
            'location' => '{"city":"S\u00e3o Paulo","state":"SP"}',
            'photos' => '[{"id":"demo-luna","url":"\/demo-pets\/luna.png","path":null}]',
            'health' => '{"vaccinated":true,"neutered":true,"dewormed":true}',
            'personality' => '["carinhosa","soci\u00e1vel"]',
        ]);

        Pet::create([
            'name' => 'Mingau',
            'slug' => 'mingau-sem-foto',
            'organization_id' => $org->id,
            'status' => 'published',
            'species' => 'cat',
            'sex' => 'male',
            'size' => 'small',
            'location' => ['city' => 'Curitiba', 'state' => 'PR'],
            'published_at' => now()->addMinute(),
        ]);

        $this->getJson('/api/v1/pets?state=sp')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/pets?city=porto&state=SP&species=dog&size=small&sex=male')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $nativePet->slug)
            ->assertJsonPath('data.0.location.city', 'Porto Ferreira');

        $this->getJson('/api/v1/pets?city=S%C3%A3o%20Paulo&state=SP')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'luna-legada')
            ->assertJsonPath('data.0.location.city', 'São Paulo')
            ->assertJsonPath('data.0.personality.1', 'sociável');

        // Paginação real do catálogo
        $this->getJson('/api/v1/pets?per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', 1);

        $this->getJson('/api/v1/pets?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 2);

        // Destaques da Home priorizam animais publicados com foto de capa
        $this->getJson('/api/v1/pets?featured=1&per_page=6')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $nativePet->slug);
    }

    public function test_adoption_flow_reservation_withdrawal_rejection_completion_external_adoption_and_republication(): void
    {
        $org = Organization::create([
            'name' => 'Amigos de Porto Ferreira',
            'slug' => 'amigos-pf',
            'type' => 'ngo',
            'city' => 'Porto Ferreira',
            'state' => 'SP',
            'verification_status' => 'approved',
        ]);
        $owner = User::create([
            'name' => 'Responsável ONG',
            'email' => 'ong@pf.test',
            'password' => 'Amigo#12',
            'role' => 'organization_owner',
            'organization_id' => $org->id,
            'status' => 'active',
        ]);
        $owner->markEmailAsVerified();
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@pf.test',
            'password' => 'Amigo#12',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $adopter1 = User::create(['name' => 'Candidato 1', 'email' => 'c1@pf.test', 'password' => 'Amigo#12', 'role' => 'adopter', 'status' => 'active']);
        $adopter2 = User::create(['name' => 'Candidato 2', 'email' => 'c2@pf.test', 'password' => 'Amigo#12', 'role' => 'adopter', 'status' => 'active']);
        $adopter3 = User::create(['name' => 'Candidato 3', 'email' => 'c3@pf.test', 'password' => 'Amigo#12', 'role' => 'adopter', 'status' => 'active']);
        $adopter4 = User::create(['name' => 'Candidato 4', 'email' => 'c4@pf.test', 'password' => 'Amigo#12', 'role' => 'adopter', 'status' => 'active']);

        $pet = Pet::create([
            'name' => 'Pipoca',
            'slug' => 'pipoca-pf',
            'organization_id' => $org->id,
            'status' => 'published',
            'species' => 'dog',
            'sex' => 'female',
            'size' => 'small',
            'description' => 'Pipoca é uma cachorrinha muito carinhosa e alegre em busca de um lar responsável.',
            'cover_photo' => '/demo-pets/pipoca.png',
            'location' => ['city' => 'Porto Ferreira', 'state' => 'SP'],
            'published_at' => now(),
        ]);

        $payload = [
            'name' => 'Candidato',
            'email' => 'c@pf.test',
            'phone' => '19999999999',
            'city' => 'Porto Ferreira',
            'state' => 'SP',
            'housing_type' => 'house',
            'has_other_pets' => false,
            'experience' => 'Sempre tive cães.',
            'message' => 'Tenho espaço seguro e tempo para cuidar.',
            'privacy_consent' => true,
        ];

        // Três candidatos enviam solicitação enquanto o animal está publicado
        $req1Id = $this->actingAs($adopter1)->postJson("/api/v1/pets/{$pet->id}/adoption-requests", [...$payload, 'email' => $adopter1->email])->assertCreated()->json('data.id');
        $req2Id = $this->actingAs($adopter2)->postJson("/api/v1/pets/{$pet->id}/adoption-requests", [...$payload, 'email' => $adopter2->email])->assertCreated()->json('data.id');
        $req3Id = $this->actingAs($adopter3)->postJson("/api/v1/pets/{$pet->id}/adoption-requests", [...$payload, 'email' => $adopter3->email])->assertCreated()->json('data.id');

        // 1. Aprovação e reserva: aprovar req1 muda o animal para adoption_in_progress e preserva req2 e req3
        $this->actingAs($owner)
            ->patchJson("/api/v1/organization/adoption-requests/{$req1Id}", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('adoption_in_progress', $pet->fresh()->status->value);
        $this->assertSame('new', AdoptionRequest::find($req2Id)->status->value);
        $this->assertSame('new', AdoptionRequest::find($req3Id)->status->value);

        // Impede novas solicitações enquanto reservado
        $this->actingAs($adopter4)
            ->postJson("/api/v1/pets/{$pet->id}/adoption-requests", [...$payload, 'email' => $adopter4->email])
            ->assertStatus(409);

        // Impede aprovar segunda solicitação simultaneamente (API e índice único parcial no MongoDB)
        $this->actingAs($owner)
            ->patchJson("/api/v1/organization/adoption-requests/{$req2Id}", ['status' => 'approved'])
            ->assertStatus(409);

        // Impede marcar adotado manualmente ou republicar enquanto existir solicitação aprovada
        $this->actingAs($owner)
            ->postJson("/api/v1/organization/pets/{$pet->id}/mark-adopted")
            ->assertStatus(409);
        $this->actingAs($owner)
            ->postJson("/api/v1/organization/pets/{$pet->id}/publish")
            ->assertStatus(409);

        // Admin também não pode burlar a reserva ativa para publicar ou marcar adotado
        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/pets/{$pet->id}/status", ['status' => 'published'])
            ->assertStatus(409);
        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/pets/{$pet->id}/status", ['status' => 'adopted'])
            ->assertStatus(409);

        // 2. Desistência pelo adotante aprovado libera a reserva e animal volta para published
        $this->actingAs($adopter1)
            ->patchJson("/api/v1/account/adoptions/{$req1Id}", ['action' => 'withdraw'])
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        $this->assertSame('published', $pet->fresh()->status->value);

        // Agora a ONG pode aprovar req2
        $this->actingAs($owner)
            ->patchJson("/api/v1/organization/adoption-requests/{$req2Id}", ['status' => 'approved'])
            ->assertOk();
        $this->assertSame('adoption_in_progress', $pet->fresh()->status->value);

        // Se a ONG rejeitar req2, a reserva também é liberada e o animal volta para published
        $this->actingAs($owner)
            ->patchJson("/api/v1/organization/adoption-requests/{$req2Id}", ['status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->assertSame('published', $pet->fresh()->status->value);

        // Candidato 4 agora consegue enviar solicitação pois o animal voltou a ficar disponível
        $req4Id = $this->actingAs($adopter4)
            ->postJson("/api/v1/pets/{$pet->id}/adoption-requests", [...$payload, 'email' => $adopter4->email])
            ->assertCreated()
            ->json('data.id');

        // 3. Conclusão: ONG aprova req3; adotante 3 confirma recebimento; req4 é encerrada automaticamente com histórico
        $this->actingAs($owner)
            ->patchJson("/api/v1/organization/adoption-requests/{$req3Id}", ['status' => 'approved'])
            ->assertOk();
        $this->assertSame('adoption_in_progress', $pet->fresh()->status->value);

        // Candidato não aprovado (adopter4) não pode confirmar
        $this->actingAs($adopter4)
            ->patchJson("/api/v1/account/adoptions/{$req4Id}", ['action' => 'confirm'])
            ->assertStatus(409);

        // Adotante aprovado (adopter3) confirma o recebimento
        $this->actingAs($adopter3)
            ->patchJson("/api/v1/account/adoptions/{$req3Id}", ['action' => 'confirm'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('adopted', $pet->fresh()->status->value);
        $this->assertNotNull($pet->fresh()->adopted_at);

        // Solicitação concorrente aberta (req4) foi encerrada como rejected com motivo no histórico
        $closedReq4 = AdoptionRequest::find($req4Id);
        $this->assertSame('rejected', $closedReq4->status->value);
        $lastHistory4 = collect($closedReq4->status_history)->last();
        $this->assertSame('rejected', $lastHistory4['status']);
        $this->assertSame('Animal adotado por outro candidato confirmado.', $lastHistory4['reason']);

        // Adotante 3 avalia a adoção concluída
        $this->actingAs($adopter3)
            ->postJson("/api/v1/account/adoptions/{$req3Id}/review", [
                'rating' => 5,
                'comment' => 'Adoção muito responsável e acompanhamento excelente.',
            ])
            ->assertCreated();

        // 4. Republicação após devolução preserva histórico e avaliação concluída
        $this->actingAs($owner)
            ->postJson("/api/v1/organization/pets/{$pet->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertSame('published', $pet->fresh()->status->value);
        $this->assertNull($pet->fresh()->adopted_at);
        $preservedReq3 = AdoptionRequest::find($req3Id);
        $this->assertSame('completed', $preservedReq3->status->value);
        $this->assertSame(5, $preservedReq3->review['rating']);

        // Restrição de duplicidade por usuário e animal mantida após republicação
        $this->actingAs($adopter1)
            ->postJson("/api/v1/pets/{$pet->id}/adoption-requests", [...$payload, 'email' => $adopter1->email])
            ->assertStatus(409);

        // 5. Adoção externa (markAdopted sem solicitação aprovada) encerra solicitações abertas sem marcar completed
        $adopter5 = User::create(['name' => 'Candidato 5', 'email' => 'c5@pf.test', 'password' => 'Amigo#12', 'role' => 'adopter', 'status' => 'active']);
        $req5Id = $this->actingAs($adopter5)
            ->postJson("/api/v1/pets/{$pet->id}/adoption-requests", [...$payload, 'email' => $adopter5->email])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($owner)
            ->postJson("/api/v1/organization/pets/{$pet->id}/mark-adopted")
            ->assertOk()
            ->assertJsonPath('data.status', 'adopted');

        $closedReq5 = AdoptionRequest::find($req5Id);
        $this->assertSame('rejected', $closedReq5->status->value);
        $this->assertSame('Animal adotado externamente fora da plataforma.', collect($closedReq5->status_history)->last()['reason']);
    }

    public function test_organization_pet_management_drafts_editing_photos_publishing_and_permissions(): void
    {
        Storage::fake('public');

        $orgA = Organization::create([
            'name' => 'ONG Abrigo Feliz',
            'slug' => 'ong-abrigo-feliz',
            'type' => 'ngo',
            'city' => 'Porto Ferreira',
            'state' => 'SP',
            'verification_status' => 'approved',
        ]);
        $ownerA = User::create([
            'name' => 'Responsável A',
            'email' => 'owner-a@pf.test',
            'password' => 'Amigo#12',
            'role' => 'organization_owner',
            'organization_id' => $orgA->id,
            'status' => 'active',
        ]);
        $ownerA->markEmailAsVerified();

        $orgB = Organization::create([
            'name' => 'Protetor Independente B',
            'slug' => 'protetor-b',
            'type' => 'independent_protector',
            'city' => 'São Carlos',
            'state' => 'SP',
            'verification_status' => 'approved',
        ]);
        $ownerB = User::create([
            'name' => 'Responsável B',
            'email' => 'owner-b@pf.test',
            'password' => 'Amigo#12',
            'role' => 'organization_owner',
            'organization_id' => $orgB->id,
            'status' => 'active',
        ]);
        $ownerB->markEmailAsVerified();

        // 1. Cria rascunho interrompido (apenas nome, sem foto, descrição ou localização)
        $draftResponse = $this->actingAs($ownerA)
            ->postJson('/api/v1/organization/pets', [
                'name' => 'Bolinha',
                'species' => 'dog',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.name', 'Bolinha');

        $petId = $draftResponse->json('data.id');

        // 2. Tentativa de publicar rascunho incompleto falha com erros de validação (422)
        $this->actingAs($ownerA)
            ->postJson("/api/v1/organization/pets/{$petId}/publish")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description', 'location.city', 'location.state', 'photo']);

        // 3. Outra organização (ownerB) não pode visualizar, editar, enviar foto, publicar ou arquivar o animal de orgA
        $this->actingAs($ownerB)->getJson("/api/v1/organization/pets/{$petId}")->assertForbidden();
        $this->actingAs($ownerB)->patchJson("/api/v1/organization/pets/{$petId}", ['name' => 'Invasão'])->assertForbidden();
        $this->actingAs($ownerB)->postJson("/api/v1/organization/pets/{$petId}/publish")->assertForbidden();
        $this->actingAs($ownerB)->deleteJson("/api/v1/organization/pets/{$petId}")->assertForbidden();

        // 4. Retoma rascunho e atualiza todos os campos (incluindo saúde, personalidade e localização)
        $this->actingAs($ownerA)
            ->patchJson("/api/v1/organization/pets/{$petId}", [
                'name' => 'Bolinha Feliz',
                'species' => 'dog',
                'breed' => 'Vira-lata caramelo',
                'sex' => 'male',
                'size' => 'medium',
                'approximate_age' => '3 anos',
                'description' => 'Bolinha é um cachorro dócil, vacinado e muito sociável com crianças e outros animais.',
                'personality' => ['dócil', 'brincalhão', 'sociável'],
                'health' => [
                    'vaccinated' => true,
                    'neutered' => true,
                    'dewormed' => true,
                    'special_needs' => false,
                    'notes' => 'Vacinado com V10 e antirrábica.',
                ],
                'location' => [
                    'city' => 'Porto Ferreira',
                    'state' => 'sp',
                    'neighborhood' => 'Centro',
                ],
                'status' => 'published', // Não deve alterar o status diretamente via update
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Bolinha Feliz')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.health.vaccinated', true)
            ->assertJsonPath('data.health.notes', 'Vacinado com V10 e antirrábica.')
            ->assertJsonPath('data.location.state', 'SP')
            ->assertJsonPath('data.location.neighborhood', 'Centro');

        // 5. Gestão de fotografias: upload de duas fotos, definição de foto principal e remoção
        $tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $photo1Resp = $this->actingAs($ownerA)
            ->post("/api/v1/organization/pets/{$petId}/photos", [
                'photo' => UploadedFile::fake()->createWithContent('foto1.png', $tinyPng),
                'is_cover' => '1',
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $photo1 = $photo1Resp->json('data.photos.0');
        $this->assertSame($photo1['url'], $photo1Resp->json('data.cover_photo'));

        $photo2Resp = $this->actingAs($ownerA)
            ->post("/api/v1/organization/pets/{$petId}/photos", [
                'photo' => UploadedFile::fake()->createWithContent('foto2.png', $tinyPng),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(2, 'data.photos');

        $photo2 = $photo2Resp->json('data.photos.1');
        $photo2Stored = \App\Models\Pet::find($petId)->photos[1];
        $this->assertSame($photo1['url'], $photo2Resp->json('data.cover_photo'));

        // Define a segunda foto como capa
        $this->actingAs($ownerA)
            ->patchJson("/api/v1/organization/pets/{$petId}/photos/{$photo2['id']}")
            ->assertOk()
            ->assertJsonPath('data.cover_photo', $photo2['url']);

        // 6. Publica o animal após preencher dados obrigatórios e foto
        $this->actingAs($ownerA)
            ->postJson("/api/v1/organization/pets/{$petId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        // Enquanto publicado, não permite esvaziar descrição obrigatória
        $this->actingAs($ownerA)
            ->patchJson("/api/v1/organization/pets/{$petId}", ['description' => 'Curta'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description']);

        // Remove a foto de capa (foto2); foto1 passa a ser a capa automaticamente
        $this->actingAs($ownerA)
            ->deleteJson("/api/v1/organization/pets/{$petId}/photos/{$photo2['id']}")
            ->assertOk()
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonPath('data.cover_photo', $photo1['url']);

        $deletionId = \App\Models\PhotoDeletion::identifier((string) $petId, $photo2['id']);
        $this->assertSame('completed', \App\Models\PhotoDeletion::find($deletionId)->status);
        Storage::disk('public')->assertMissing($photo2Stored['path']);
        $this->actingAs($ownerA)->deleteJson("/api/v1/organization/pets/{$petId}/photos/{$photo2['id']}")
            ->assertOk()->assertJsonPath('photo_cleanup', 'completed');
        $this->assertSame(1, \App\Models\PhotoDeletion::whereKey($deletionId)->count());

        // Impede remover a última foto enquanto o animal está publicado
        $this->actingAs($ownerA)
            ->deleteJson("/api/v1/organization/pets/{$petId}/photos/{$photo1['id']}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);

        // 7. Listagem do painel com filtro de status, busca e contadores
        $this->actingAs($ownerA)
            ->getJson('/api/v1/organization/pets?status=published&search=Bolinha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('counts.total', 1)
            ->assertJsonPath('counts.published', 1);

        // 8. Arquivamento do animal
        $this->actingAs($ownerA)
            ->deleteJson("/api/v1/organization/pets/{$petId}")
            ->assertOk();

        $this->assertSame('archived', Pet::find($petId)->status->value);
    }

    public function test_draft_pet_slug_updates_when_renamed_before_first_publication_and_preserves_slug_after_publication(): void
    {
        Storage::fake('public');

        $org = Organization::create([
            'name' => 'ONG Slug Teste',
            'slug' => 'ong-slug-teste',
            'type' => 'ngo',
            'verification_status' => 'approved',
            'city' => 'Porto Ferreira',
            'state' => 'SP',
        ]);

        $owner = User::create([
            'name' => 'Responsável Slug',
            'email' => 'slug@pf.test',
            'password' => 'Amigo#12',
            'role' => 'organization_owner',
            'organization_id' => $org->id,
            'status' => 'active',
        ]);
        $owner->markEmailAsVerified();

        $draft1 = $this->actingAs($owner)
            ->postJson('/api/v1/organization/pets', [
                'name' => 'Bento Rascunho',
                'description' => 'Bento é um cão muito dócil, carinhoso e pronto para adoção responsável.',
                'location' => ['city' => 'Porto Ferreira', 'state' => 'SP'],
            ])
            ->assertCreated();

        $draft1Id = $draft1->json('data.id');
        $this->assertSame('bento-rascunho', $draft1->json('data.slug'));

        // Renomear rascunho antes da primeira publicação deve atualizar o slug
        $this->actingAs($owner)
            ->patchJson("/api/v1/organization/pets/{$draft1Id}", [
                'name' => 'Bento Caramelo',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'bento-caramelo');

        // Editar outro campo mantendo o mesmo nome deve preservar o próprio slug sem sufixo "-2"
        $this->actingAs($owner)
            ->patchJson("/api/v1/organization/pets/{$draft1Id}", [
                'name' => 'Bento Caramelo',
                'breed' => 'SRD',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'bento-caramelo');

        // Outro rascunho renomeado para o mesmo nome deve receber sufixo único "-2"
        $draft2 = $this->actingAs($owner)
            ->postJson('/api/v1/organization/pets', [
                'name' => 'Temporário',
            ])
            ->assertCreated();

        $draft2Id = $draft2->json('data.id');
        $this->actingAs($owner)
            ->patchJson("/api/v1/organization/pets/{$draft2Id}", [
                'name' => 'Bento Caramelo',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'bento-caramelo-2');

        // Publica o primeiro animal e confirma que renomeações posteriores preservam o slug público
        $tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $this->actingAs($owner)
            ->post("/api/v1/organization/pets/{$draft1Id}/photos", [
                'photo' => UploadedFile::fake()->createWithContent('capa.png', $tinyPng),
                'is_cover' => '1',
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/v1/organization/pets/{$draft1Id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.slug', 'bento-caramelo');

        $this->actingAs($owner)
            ->patchJson("/api/v1/organization/pets/{$draft1Id}", [
                'name' => 'Bento Caramelo Oficial',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Bento Caramelo Oficial')
            ->assertJsonPath('data.slug', 'bento-caramelo');
    }

    public function test_email_verification_resend_handles_smtp_failure_gracefully_without_500(): void
    {
        $user = User::create([
            'name' => 'Adotante Sem Verificação',
            'email' => 'unverified@pf.test',
            'password' => 'Amigo#12',
            'role' => 'adopter',
            'status' => 'active',
        ]);

        // Caso 1: funcionamento normal retorna 200
        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Link de verificação enviado.');

        // Caso 2: falha de transporte SMTP retorna 503 controlado sem vazar detalhes internos
        $failingDispatcher = \Mockery::mock(\Illuminate\Contracts\Notifications\Dispatcher::class);
        $failingDispatcher->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('SMTP connection refused: credential_secret_123'));
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, $failingDispatcher);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Não foi possível enviar o e-mail de confirmação no momento. Tente novamente em instantes.');

        $this->assertStringNotContainsString('credential_secret_123', $response->getContent());
        $this->assertStringNotContainsString('Link de verificação enviado', $response->getContent());
    }

    public function test_user_favorite_ids_bson_array_cast_preserves_legacy_json_string_and_supports_favorite_pets(): void
    {
        $organization = Organization::create([
            'name' => 'ONG Favoritos PF',
            'slug' => 'ong-favoritos-pf',
            'type' => 'ngo',
            'verification_status' => 'approved',
            'city' => 'Porto Ferreira',
            'state' => 'SP',
        ]);

        $pet1 = Pet::create([
            'organization_id' => (string) $organization->id,
            'name' => 'Amora Favorita',
            'slug' => 'amora-favorita',
            'species' => 'dog',
            'sex' => 'female',
            'size' => 'small',
            'description' => 'Cachorrinha dócil disponível para adoção responsável.',
            'status' => 'published',
            'location' => ['city' => 'Porto Ferreira', 'state' => 'SP'],
        ]);

        $pet2 = Pet::create([
            'organization_id' => (string) $organization->id,
            'name' => 'Chico Favorito',
            'slug' => 'chico-favorito',
            'species' => 'cat',
            'sex' => 'male',
            'size' => 'small',
            'description' => 'Gatinho tranquilo disponível para adoção responsável.',
            'status' => 'published',
            'location' => ['city' => 'Porto Ferreira', 'state' => 'SP'],
        ]);

        $user = User::create([
            'name' => 'Adotante Favoritos',
            'email' => 'favoritos@pf.test',
            'password' => 'Amigo#12',
            'role' => 'adopter',
            'status' => 'active',
        ]);

        // Simula registro antigo com favorite_ids gravado como string JSON no MongoDB
        DB::connection('mongodb')->getCollection('users')->updateOne(
            ['email' => 'favoritos@pf.test'],
            ['$set' => ['favorite_ids' => json_encode([(string) $pet1->id])]]
        );

        $freshUser = User::findOrFail($user->id);
        $this->assertSame([(string) $pet1->id], $freshUser->favorite_ids);

        $this->actingAs($freshUser)
            ->getJson('/api/v1/account/favorites')
            ->assertOk()
            ->assertJsonPath('data', [(string) $pet1->id]);

        $this->actingAs($freshUser)
            ->getJson('/api/v1/account/favorite-pets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Amora Favorita');

        // Adiciona segundo favorito convertendo string legada para BSON array nativo sem perda
        $this->actingAs($freshUser)
            ->putJson("/api/v1/account/favorites/{$pet2->id}")
            ->assertNoContent();

        $this->actingAs($freshUser->fresh())
            ->getJson('/api/v1/account/favorite-pets')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
