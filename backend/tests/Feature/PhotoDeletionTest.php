<?php

namespace Tests\Feature;

use App\Contracts\CloudinaryClient;
use App\Models\PhotoDeletion;
use App\Services\Photos\PhotoDeletionProcessor;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoDeletionTest extends TestCase
{
    private function processor(bool $referenced = false): PhotoDeletionProcessor
    {
        return new class($referenced) extends PhotoDeletionProcessor {
            public function __construct(private bool $inUse) {}
            protected function referenced(PhotoDeletion $task): bool { return $this->inUse; }
            protected function record(PhotoDeletion $task, array $changes): void { $task->fill($changes); }
        };
    }

    private function task(array $photo): PhotoDeletion
    {
        return new PhotoDeletion(['pet_id' => 'pet-one', 'photo_id' => 'photo-one', 'status' => 'pending', 'photo' => $photo]);
    }

    public function test_network_failure_can_be_retried_and_already_missing_is_success(): void
    {
        $client = \Mockery::mock(CloudinaryClient::class);
        $client->shouldReceive('destroy')->once()->ordered()->with('adoptplace/pets/pet-one/photo-one')->andThrow(new \RuntimeException('network failed'));
        $client->shouldReceive('destroy')->once()->ordered()->with('adoptplace/pets/pet-one/photo-one')->andReturn(['result' => 'not found']);
        $this->app->instance(CloudinaryClient::class, $client);
        $task = $this->task(['provider' => 'cloudinary', 'public_id' => 'adoptplace/pets/pet-one/photo-one']);
        $processor = $this->processor();
        $this->assertFalse($processor->process($task));
        $this->assertSame('failed', $task->status);
        $this->assertTrue($processor->process($task));
        $this->assertSame('completed', $task->status);
        $this->assertTrue($processor->process($task)); // No third provider call.
    }

    public function test_local_cleanup_is_idempotent_and_referenced_photos_are_blocked(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('pets/pet-one/image.png', 'image');
        $photo = ['provider' => 'storage', 'disk' => 'public', 'path' => 'pets/pet-one/image.png'];
        $task = $this->task($photo);
        $this->assertFalse($this->processor(true)->process($task));
        Storage::disk('public')->assertExists($photo['path']);
        $this->assertSame('blocked', $task->status);
        $this->assertTrue($this->processor()->process($task));
        Storage::disk('public')->assertMissing($photo['path']);
        $this->assertTrue($this->processor()->process($this->task($photo)));
    }

    public function test_foreign_paths_are_never_deleted_and_task_ids_are_unique_per_pet(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('pets/other/image.png', 'image');
        $task = $this->task(['path' => 'pets/other/image.png']);
        $this->assertFalse($this->processor()->process($task));
        Storage::disk('public')->assertExists('pets/other/image.png');
        $this->assertNotSame(PhotoDeletion::identifier('one', 'p'), PhotoDeletion::identifier('two', 'p'));
        $this->assertSame(PhotoDeletion::identifier('one', 'p'), PhotoDeletion::identifier('one', 'p'));
    }

    public function test_reconciliation_requires_explicit_matching_database_and_bounded_limit(): void
    {
        config(['database.connections.mongodb.database' => 'isolated_test']);
        $this->artisan('photos:reconcile')->assertFailed();
        $this->artisan('photos:reconcile', ['--database' => 'wrong'])->assertFailed();
        $this->artisan('photos:reconcile', ['--database' => 'isolated_test', '--limit' => 0])->assertFailed();
    }
}
