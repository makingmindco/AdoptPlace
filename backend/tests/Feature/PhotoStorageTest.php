<?php

namespace Tests\Feature;

use App\Contracts\CloudinaryClient;
use App\Contracts\PhotoStorage;
use App\Http\Resources\PetResource;
use App\Models\Pet;
use App\Services\Photos\CloudinaryPhotoStorage;
use App\Services\Photos\LocalPhotoStorage;
use App\Services\Photos\PhotoManager;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PhotoStorageTest extends TestCase
{
    public function test_local_upload_and_compensation_use_recorded_disk(): void
    {
        Storage::fake('public');
        config(['filesystems.pet_media_disk' => 'public']);
        $storage = new LocalPhotoStorage;
        $photo = $storage->upload(UploadedFile::fake()->create('pet.png', 1, 'image/png'), 'pet-one');
        Storage::disk('public')->assertExists($photo['path']);
        config(['filesystems.pet_media_disk' => 's3']);
        $storage->delete($photo);
        Storage::disk('public')->assertMissing($photo['path']);
    }

    public function test_cloudinary_upload_uses_unique_id_and_https_and_can_compensate(): void
    {
        $client = \Mockery::mock(CloudinaryClient::class);
        $client->shouldReceive('upload')->once()->andReturnUsing(function ($path, $options) {
            $this->assertFileExists($path);
            $this->assertFalse($options['overwrite']);
            $this->assertSame('image', $options['resource_type']);
            return ['public_id' => $options['public_id'], 'secure_url' => 'https://images.example.test/pet.png'];
        });
        $client->shouldReceive('destroy')->once()->with(\Mockery::pattern('~^adoptplace/pets/pet-one/~'))->andReturn(['result' => 'ok']);
        $manager = new PhotoManager(new CloudinaryPhotoStorage($client));
        try {
            $manager->uploadAndPersist(UploadedFile::fake()->create('pet.png', 1, 'image/png'), 'pet-one', function () {
                throw new \RuntimeException('Definite persistence failure');
            });
            $this->fail('Persistence failure was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('Definite persistence failure', $e->getMessage());
        }
    }

    public function test_successful_persistence_does_not_delete_and_resource_hides_internal_metadata(): void
    {
        $storage = \Mockery::mock(PhotoStorage::class);
        $photo = ['id' => 'photo-one', 'url' => 'https://example.test/photo.png', 'provider' => 'cloudinary', 'public_id' => 'internal', 'path' => null];
        $storage->shouldReceive('upload')->once()->andReturn($photo);
        $storage->shouldNotReceive('delete');
        $result = (new PhotoManager($storage))->uploadAndPersist(UploadedFile::fake()->create('pet.png'), 'pet-one', fn ($value) => $value);
        $this->assertSame($photo, $result);
        $data = (new PetResource(new Pet(['photos' => [$photo]])))->resolve(Request::create('/'));
        $this->assertSame(['id' => 'photo-one', 'url' => $photo['url'], 'path' => null], $data['photos'][0]);
    }

    public function test_cleanup_failure_is_explicit_and_sanitized(): void
    {
        $storage = \Mockery::mock(PhotoStorage::class);
        $storage->shouldReceive('upload')->andReturn(['id' => 'photo-one', 'provider' => 'cloudinary']);
        $storage->shouldReceive('delete')->once()->andThrow(new \RuntimeException('secret provider details'));
        try {
            $manager = \Mockery::mock(PhotoManager::class, [$storage])->makePartial()->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('queueCleanup')->once();
            $manager->uploadAndPersist(UploadedFile::fake()->create('pet.png'), 'pet-one', fn () => throw new \RuntimeException('save failed'));
            $this->fail('Cleanup failure was swallowed');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function test_photo_ownership_is_validated_and_demo_reference_is_supported(): void
    {
        $manager = new \App\Services\Photos\PhotoDeletionProcessor;
        $this->assertSame('demo', $manager->normalize(['path' => null, 'url' => '/demo-pets/luna.png'], 'pet-one')['provider']);
        foreach ([['provider' => 'cloudinary'], ['path' => 'pets/old.png'], ['path' => null, 'url' => 'https://unknown.test/photo.png']] as $photo) {
            try {
                $manager->normalize($photo, 'pet-one');
                $this->fail('Unsafe deletion was allowed');
            } catch (\RuntimeException $e) {
                $this->assertSame('Photo ownership cannot be established.', $e->getMessage());
            }
        }
    }

    public function test_failed_upload_never_attempts_persistence(): void
    {
        $client = \Mockery::mock(CloudinaryClient::class);
        $client->shouldReceive('upload')->once()->andThrow(new \RuntimeException('secret details'));
        $client->shouldNotReceive('destroy');
        try {
            (new PhotoManager(new CloudinaryPhotoStorage($client)))->uploadAndPersist(
                UploadedFile::fake()->create('pet.png'), 'pet-one', fn () => $this->fail('Unexpected persistence'),
            );
            $this->fail('Upload failure was swallowed');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function test_uncertain_database_outcome_does_not_delete_potentially_referenced_photo(): void
    {
        $storage = \Mockery::mock(PhotoStorage::class);
        $storage->shouldReceive('upload')->andReturn(['id' => 'photo-one', 'provider' => 'cloudinary']);
        $storage->shouldNotReceive('delete');
        try {
            $manager = \Mockery::mock(PhotoManager::class, [$storage])->makePartial()->shouldAllowMockingProtectedMethods();
            $manager->shouldReceive('queueCleanup')->once();
            $manager->uploadAndPersist(UploadedFile::fake()->create('pet.png'), 'pet-one',
                fn () => throw new \MongoDB\Driver\Exception\RuntimeException('uncertain transaction result'));
            $this->fail('Uncertain result was swallowed');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }
}
