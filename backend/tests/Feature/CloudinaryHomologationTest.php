<?php

namespace Tests\Feature;

use App\Contracts\HomologationCloudinaryClient;
use Tests\TestCase;

class CloudinaryHomologationTest extends TestCase
{
    private string $directory;
    private string $originalStorage;
    private array $originalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = [];
        foreach (['CLOUDINARY_HOMOLOGATION_ENABLED', 'CLOUDINARY_CLOUD_NAME'] as $key) {
            $this->originalEnvironment[$key] = getenv($key);
        }
        putenv('CLOUDINARY_HOMOLOGATION_ENABLED=1');
        putenv('CLOUDINARY_CLOUD_NAME=mock-cloud');
        $this->originalStorage = storage_path();
        $this->directory = sys_get_temp_dir().'/adoptplace-cloudinary-'.bin2hex(random_bytes(12));
        $this->app->useStoragePath($this->directory);
        $client = $this->clientMock();
        $client->shouldNotReceive('upload');
        $client->shouldNotReceive('inspect');
        $client->shouldNotReceive('destroy');
        $this->app->instance(HomologationCloudinaryClient::class, $client);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        foreach ($this->originalEnvironment as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function clientMock()
    {
        $client = \Mockery::mock(HomologationCloudinaryClient::class);
        $client->shouldReceive('preflight')->andReturnNull()->byDefault();
        return $client;
    }

    private function prepare(): array
    {
        $this->artisan('cloudinary:homologate', ['phase' => 'prepare', '--folder-mode' => 'dynamic'])->assertSuccessful();
        return json_decode(file_get_contents(glob($this->directory.'/app/private/cloudinary-homologation/*.json')[0]), true);
    }

    private function phaseOptions(array $m, string $phase): array
    {
        return ['phase' => $phase, '--run' => $m['run'], '--confirm' => $m['public_id']];
    }

    private function asset(array $m): array
    {
        return ['public_id' => $m['public_id'], 'asset_id' => 'mock-asset-id', 'resource_type' => 'image',
            'type' => 'upload', 'secure_url' => 'https://example.test/synthetic.png', 'asset_folder' => $m['asset_folder'],
            'context' => ['custom' => ['adoptplace_homologation_run' => $m['run']]]];
    }

    public function test_disabled_or_unconfirmed_execution_never_calls_provider(): void
    {
        putenv('CLOUDINARY_HOMOLOGATION_ENABLED=0');
        $this->artisan('cloudinary:homologate')->assertFailed();
        putenv('CLOUDINARY_HOMOLOGATION_ENABLED=1');
        $m = $this->prepare();
        $this->assertSame('reserved', $m['state']);
        $this->artisan('cloudinary:homologate', ['phase' => 'upload', '--run' => $m['run']])
            ->expectsOutput('Diagnostic: confirmation_mismatch')->assertFailed();
        $this->assertArrayNotHasKey('api_secret', $m);
        $this->assertSame([], config('database.connections'));
    }

    public function test_full_cycle_uses_exact_id_and_repeated_deletion_is_safe(): void
    {
        $m = $this->prepare();
        $client = $this->clientMock();
        $client->shouldReceive('upload')->once()->andReturnUsing(function ($file, $options) use ($m) {
            $this->assertLessThan(1024, filesize($file));
            $this->assertSame($m['public_id'], $options['public_id']);
            $this->assertFalse($options['overwrite']);
            $this->assertSame($m['asset_folder'], $options['asset_folder']);
            return $this->asset($m);
        });
        $client->shouldReceive('inspect')->twice()->with($m['public_id'])->andReturn($this->asset($m));
        $client->shouldReceive('inspect')->once()->with($m['public_id'])->andReturn(null);
        $client->shouldReceive('destroy')->once()->with($m['public_id'])->andReturn(['result' => 'ok']);
        $client->shouldReceive('destroy')->once()->with($m['public_id'])->andReturn(['result' => 'not found']);
        $this->app->instance(HomologationCloudinaryClient::class, $client);
        foreach (['upload', 'verify', 'delete', 'delete'] as $phase) {
            $this->artisan('cloudinary:homologate', $this->phaseOptions($m, $phase))->assertSuccessful();
        }
        $result = json_decode(file_get_contents($this->directory.'/app/private/cloudinary-homologation/'.$m['run'].'.json'), true);
        $this->assertSame('deleted', $result['state']);
    }

    public function test_upload_timeout_keeps_intent_and_cannot_be_blindly_repeated(): void
    {
        $m = $this->prepare();
        $client = $this->clientMock();
        $client->shouldReceive('upload')->once()->andThrow(new \RuntimeException('secret credential details'));
        $client->shouldNotReceive('destroy');
        $client->shouldNotReceive('inspect');
        $this->app->instance(HomologationCloudinaryClient::class, $client);
        $this->artisan('cloudinary:homologate', $this->phaseOptions($m, 'upload'))
            ->expectsOutput('Diagnostic: upload_remote_outcome_uncertain')->assertFailed();
        $this->artisan('cloudinary:homologate', $this->phaseOptions($m, 'upload'))
            ->expectsOutput('Diagnostic: upload_state_invalid')->assertFailed();
        $contents = file_get_contents($this->directory.'/app/private/cloudinary-homologation/'.$m['run'].'.json');
        $this->assertSame('upload_attempted', json_decode($contents, true)['state']);
        $this->assertStringNotContainsString('secret', $contents);
    }

    public function test_changed_asset_identity_blocks_deletion(): void
    {
        $m = $this->prepare();
        $m['state'] = 'verified';
        $m['asset_id'] = 'original-asset';
        file_put_contents($this->directory.'/app/private/cloudinary-homologation/'.$m['run'].'.json', json_encode($m));
        $client = $this->clientMock();
        $client->shouldReceive('inspect')->once()->with($m['public_id'])->andReturn($this->asset($m));
        $client->shouldNotReceive('destroy');
        $this->app->instance(HomologationCloudinaryClient::class, $client);
        $this->artisan('cloudinary:homologate', $this->phaseOptions($m, 'delete'))->assertFailed();
    }

    public function test_bootstrap_guard_disables_connections_before_providers(): void
    {
        $original = $_SERVER['argv'] ?? [];
        try {
            $_SERVER['argv'] = ['artisan', 'cloudinary:homologate', 'prepare'];
            config(['database.connections.mongodb.dsn' => 'must-not-be-used']);
            (new \App\Bootstrap\IsolateCloudinaryHomologation)->bootstrap($this->app);
            $this->assertSame([], config('database.connections'));
            $this->assertSame('disabled', config('database.default'));
            $this->assertSame('array', config('cache.default'));
        } finally {
            $_SERVER['argv'] = $original;
        }
    }

    public function test_delete_timeout_preserves_manifest_and_retry_targets_the_same_asset(): void
    {
        $m = $this->prepare();
        $m['state'] = 'verified';
        $m['asset_id'] = 'mock-asset-id';
        $path = $this->directory.'/app/private/cloudinary-homologation/'.$m['run'].'.json';
        file_put_contents($path, json_encode($m));
        $client = $this->clientMock();
        $client->shouldReceive('inspect')->once()->ordered()->with($m['public_id'])->andReturn($this->asset($m));
        $client->shouldReceive('destroy')->once()->ordered()->with($m['public_id'])->andThrow(new \RuntimeException('secret timeout details'));
        $client->shouldReceive('inspect')->once()->ordered()->with($m['public_id'])->andReturn(null);
        $client->shouldReceive('destroy')->once()->ordered()->with($m['public_id'])->andReturn(['result' => 'not found']);
        $this->app->instance(HomologationCloudinaryClient::class, $client);
        $this->artisan('cloudinary:homologate', $this->phaseOptions($m, 'delete'))->assertFailed();
        $this->assertSame('delete_attempted', json_decode(file_get_contents($path), true)['state']);
        $this->assertStringNotContainsString('secret', file_get_contents($path));
        $this->artisan('cloudinary:homologate', $this->phaseOptions($m, 'delete'))->assertSuccessful();
        $this->assertSame('deleted', json_decode(file_get_contents($path), true)['state']);
    }

    public function test_tampered_public_id_is_rejected_without_network_calls(): void
    {
        $m = $this->prepare();
        $m['public_id'] = 'adoptplace/pets/real-user/image';
        file_put_contents($this->directory.'/app/private/cloudinary-homologation/'.$m['run'].'.json', json_encode($m));
        $this->artisan('cloudinary:homologate', $this->phaseOptions($m, 'delete'))
            ->expectsOutput('Diagnostic: manifest_validation_failed')->assertFailed();
    }

    public function test_missing_credentials_are_detected_before_intent_or_network(): void
    {
        $m = $this->prepare();
        $client = $this->clientMock();
        $client->shouldReceive('preflight')->once()->andThrow(new \App\Services\Photos\HomologationDiagnostic('credentials_missing'));
        $client->shouldNotReceive('upload');
        $client->shouldNotReceive('inspect');
        $client->shouldNotReceive('destroy');
        $this->app->instance(HomologationCloudinaryClient::class, $client);
        $this->artisan('cloudinary:homologate', $this->phaseOptions($m, 'upload'))
            ->expectsOutput('Diagnostic: credentials_missing')->assertFailed();
        $this->assertSame('reserved', json_decode(file_get_contents($this->directory.'/app/private/cloudinary-homologation/'.$m['run'].'.json'), true)['state']);
    }

    public function test_invalid_remote_response_preserves_attempted_state(): void
    {
        $m = $this->prepare();
        $client = $this->clientMock();
        $client->shouldReceive('upload')->once()->andReturn(['secure_url' => 'https://example.test/file.png']);
        $client->shouldNotReceive('destroy');
        $this->app->instance(HomologationCloudinaryClient::class, $client);
        $this->artisan('cloudinary:homologate', $this->phaseOptions($m, 'upload'))
            ->expectsOutput('Diagnostic: upload_response_invalid')->assertFailed();
        $this->assertSame('upload_attempted', json_decode(file_get_contents($this->directory.'/app/private/cloudinary-homologation/'.$m['run'].'.json'), true)['state']);
    }

    public function test_provider_rejection_is_distinct_from_transport_uncertainty(): void
    {
        $m = $this->prepare();
        $client = $this->clientMock();
        $client->shouldReceive('upload')->once()->andThrow(new \Cloudinary\Api\Exception\BadRequest('sensitive response body'));
        $client->shouldNotReceive('destroy');
        $this->app->instance(HomologationCloudinaryClient::class, $client);
        $this->artisan('cloudinary:homologate', $this->phaseOptions($m, 'upload'))
            ->expectsOutput('Diagnostic: upload_remote_rejected')->assertFailed();
    }
}
