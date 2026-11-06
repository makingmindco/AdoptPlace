<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(?User $actor, string $action, Model $subject, array $metadata = []): void
    {
        AuditLog::create([
            'actor_id' => $actor?->id,
            'organization_id' => $actor?->organization_id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'metadata' => $metadata,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
