<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnsureMongoIndexes extends Command
{
    protected $signature = 'mongodb:ensure-indexes';

    protected $description = 'Cria os índices necessários para a API do AdoptPlace';

    public function handle(): int
    {
        $connection = DB::connection('mongodb');
        $indexes = [
            'users' => [
                [['email' => 1], ['unique' => true]],
                [['organization_id' => 1, 'status' => 1], []],
            ],
            'organizations' => [
                [['slug' => 1], ['unique' => true]],
                [['verification_status' => 1, 'created_at' => -1], []],
            ],
            'pets' => [
                [['slug' => 1], ['unique' => true]],
                [['status' => 1, 'published_at' => -1], []],
                [['organization_id' => 1, 'status' => 1], []],
                [['species' => 1, 'size' => 1, 'sex' => 1], []],
                [['name' => 'text', 'breed' => 'text', 'description' => 'text'], ['name' => 'pets_search']],
            ],
            'adoption_requests' => [
                [['user_id' => 1, 'pet_id' => 1], ['unique' => true, 'partialFilterExpression' => ['user_id' => ['$type' => 'string']]]],
                [['organization_id' => 1, 'review.status' => 1], []],
                [['organization_id' => 1, 'status' => 1, 'created_at' => -1], []],
                [['pet_id' => 1, 'created_at' => -1], []],
            ],
            'audit_logs' => [
                [['created_at' => -1], []],
                [['actor_id' => 1, 'created_at' => -1], []],
                [['subject_type' => 1, 'subject_id' => 1], []],
            ],
            'personal_access_tokens' => [
                [['token' => 1], ['unique' => true]],
                [['tokenable_id' => 1, 'tokenable_type' => 1], []],
            ],
        ];

        foreach ($indexes as $collection => $definitions) {
            foreach ($definitions as [$keys, $options]) {
                $connection->getCollection($collection)->createIndex($keys, $options);
            }

            $this->components->info("Índices de {$collection} verificados.");
        }

        return self::SUCCESS;
    }
}
