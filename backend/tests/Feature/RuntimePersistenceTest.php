<?php

namespace Tests\Feature;

use Illuminate\Auth\Passwords\CacheTokenRepository;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use App\Models\User;
use Tests\TestCase;

class RuntimePersistenceTest extends TestCase
{
    public function test_mongodb_drivers_resolve_without_querying_a_database(): void
    {
        config(['session.driver' => 'mongodb', 'cache.default' => 'mongodb', 'auth.passwords.users.store' => 'mongodb_passwords']);
        $session = $this->app['session']->driver('mongodb');
        $this->assertInstanceOf(\MongoDB\Laravel\Session\MongoDbSessionHandler::class, $session->getHandler());
        $cache = $this->app['cache']->store('mongodb')->getStore();
        $this->assertInstanceOf(\MongoDB\Laravel\Cache\MongoStore::class, $cache);
        Password::clearResolvedInstance('auth.password');
        $this->app->forgetInstance('auth.password');
        $this->assertInstanceOf(CacheTokenRepository::class, Password::broker()->getRepository());
        $collections = [config('session.table'), config('cache.stores.mongodb.collection'), config('cache.stores.mongodb.lock_collection'), config('cache.stores.mongodb_passwords.collection'), config('cache.stores.mongodb_passwords.lock_collection')];
        $this->assertCount(5, array_unique($collections));
        foreach ($collections as $name) {
            $this->assertStringStartsWith('adoptplace_runtime_', $name);
        }
    }

    public function test_reset_tokens_are_throttled_consumed_and_expire_in_memory(): void
    {
        config(['cache.stores.passwords_testing' => ['driver' => 'array'], 'auth.passwords.users.store' => 'passwords_testing']);
        Password::clearResolvedInstance('auth.password');
        $this->app->forgetInstance('auth.password');
        $repository = Password::broker()->getRepository();
        $user = new User(['email' => 'runtime@example.test']);
        $token = $repository->create($user);
        $this->assertTrue($repository->exists($user, $token));
        $this->app['cache']->store('array')->flush();
        $this->assertTrue($repository->exists($user, $token));
        $this->assertTrue($repository->recentlyCreatedToken($user));
        $repository->delete($user);
        $this->assertFalse($repository->exists($user, $token));
        $token = $repository->create($user);
        $this->travel(61)->minutes();
        $this->assertFalse($repository->exists($user, $token));
    }

    public function test_secure_session_cookie_and_csrf_cookie_keep_their_distinct_visibility(): void
    {
        config(['session.driver' => 'array', 'session.secure' => true]);
        $response = $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie) => $cookie->getName());
        $this->assertTrue($cookies[config('session.cookie')]->isSecure());
        $this->assertTrue($cookies[config('session.cookie')]->isHttpOnly());
        $this->assertFalse($cookies['XSRF-TOKEN']->isHttpOnly());
        $this->assertSame('lax', $cookies[config('session.cookie')]->getSameSite());
    }

    public function test_verification_signature_expires_without_server_local_state(): void
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinute(), ['id' => 'fixture', 'hash' => 'fixture']);
        $request = \Illuminate\Http\Request::create($url);
        $this->assertTrue(URL::hasValidSignature($request));
        $this->travel(2)->minutes();
        $this->assertFalse(URL::hasValidSignature($request));
    }
}
