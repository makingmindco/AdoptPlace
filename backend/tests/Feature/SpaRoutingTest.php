<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SpaRoutingTest extends TestCase
{
    public function test_spa_navigation_and_head_requests_return_the_build(): void
    {
        $directory = sys_get_temp_dir().'/adoptplace-spa-'.bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory.'/index.html', '<html>SPA test fixture</html>');
        $original = public_path();
        $this->app->usePublicPath($directory);
        try {
            foreach (['/', '/animais', '/dashboard', '/pets/luna', '/login.html'] as $path) {
                $response = $this->get($path)->assertOk();
                $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response->baseResponse);
                $this->assertSame('<html>SPA test fixture</html>', file_get_contents($response->baseResponse->getFile()->getPathname()));
            }
            $this->head('/animais')->assertOk();
            foreach (['/api/missing', '/api/v1/missing', '/sanctum/missing', '/email/verify/missing', '/storage/missing.png', '/assets/missing.js', '/missing.css'] as $path) {
                $this->get($path)->assertNotFound()->assertDontSee('SPA test fixture');
            }
            $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
            $this->get('/sanctum/csrf-cookie')->assertNoContent();
            $this->post('/animais')->assertStatus(405);
            unlink($directory.'/index.html');
            $this->get('/animais')->assertStatus(503);
        } finally {
            $this->app->usePublicPath($original);
            if (is_file($directory.'/index.html')) {
                unlink($directory.'/index.html');
            }
            rmdir($directory);
        }
    }

    public function test_forwarded_https_and_signed_urls_require_a_trusted_proxy(): void
    {
        $request = Request::create('http://example.test/login', server: [
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ]);
        config(['trustedproxy.proxies' => null]);
        $middleware = new TrustProxies;
        $middleware->handle($request, function ($request) {
            $this->assertFalse($request->isSecure());
        });
        config(['trustedproxy.proxies' => '10.0.0.2']);
        try {
            $middleware->handle($request, function ($request) {
                $this->assertTrue($request->isSecure());
                $this->assertSame('https://example.test', $request->getSchemeAndHttpHost());
                URL::setRequest($request);
                $url = URL::signedRoute('verification.verify', ['id' => 'test-user', 'hash' => 'test-hash']);
                $this->assertStringStartsWith('https://example.test/', $url);
                $incoming = Request::create(str_replace('https:', 'http:', $url), server: [
                    'REMOTE_ADDR' => '10.0.0.2',
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'HTTP_X_FORWARDED_PORT' => '443',
                ]);
                (new TrustProxies)->handle($incoming, function ($incoming) {
                    $this->assertTrue(URL::hasValidSignature($incoming));
                });
            });
        } finally {
            Request::setTrustedProxies([], 0);
        }
    }
}
