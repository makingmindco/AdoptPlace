<?php

namespace App\Console\Commands;

use App\Models\PhotoDeletion;
use App\Services\Photos\PhotoDeletionProcessor;
use Illuminate\Console\Command;

class ReconcilePhotos extends Command
{
    protected $signature = 'photos:reconcile {--database= : Exact configured database name} {--execute : Perform deletion; otherwise list only} {--limit=25}';
    protected $description = 'Inspect or explicitly retry durable photo cleanup tasks in the selected database';

    public function handle(PhotoDeletionProcessor $processor): int
    {
        $database = config('database.connections.mongodb.database');
        if (! $this->option('database') || $this->option('database') !== $database) {
            $this->error('Supply --database with the exact configured database name.');
            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 100.');
            return self::FAILURE;
        }
        $failed = false;
        foreach (PhotoDeletion::whereIn('status', ['pending', 'failed', 'blocked'])->oldest()->limit($limit)->get() as $task) {
            $this->line($task->id.' '.$task->status);
            if ($this->option('execute') && ! $processor->process($task)) {
                $failed = true;
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
