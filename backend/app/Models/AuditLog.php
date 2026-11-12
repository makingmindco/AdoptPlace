<?php

namespace App\Models;

use App\Casts\BsonArray;
use MongoDB\Laravel\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $connection = 'mongodb';

    protected $table = 'audit_logs';

    protected $fillable = [
        'actor_id', 'organization_id', 'action', 'subject_type', 'subject_id',
        'metadata', 'ip_address', 'created_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => BsonArray::class, 'created_at' => 'datetime'];
    }
}
