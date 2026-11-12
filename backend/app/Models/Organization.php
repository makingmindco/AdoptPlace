<?php

namespace App\Models;

use App\Casts\BsonArray;
use App\Enums\OrganizationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;

    protected $connection = 'mongodb';

    protected $table = 'organizations';

    protected $fillable = [
        'name', 'slug', 'type', 'document', 'description', 'city', 'state',
        'address', 'phone', 'whatsapp', 'email', 'social_links', 'logo_url',
        'verification_status', 'verification_notes', 'contact_publicly_visible',
        'approved_at', 'private_address',
    ];

    protected $hidden = ['document', 'verification_notes', 'private_address'];

    protected function casts(): array
    {
        return [
            'verification_status' => OrganizationStatus::class,
            'contact_publicly_visible' => 'boolean',
            'social_links' => BsonArray::class,
            'private_address' => 'encrypted:array',
            'approved_at' => 'datetime',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function pets()
    {
        return $this->hasMany(Pet::class);
    }

    public function isApproved(): bool
    {
        return $this->verification_status === OrganizationStatus::Approved;
    }
}
