<?php

namespace App\Policies;

use App\Models\AdoptionRequest;
use App\Models\User;

class AdoptionRequestPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, AdoptionRequest $adoptionRequest): bool
    {
        return $user->organization_id && (string) $user->organization_id === (string) $adoptionRequest->organization_id;
    }

    public function update(User $user, AdoptionRequest $adoptionRequest): bool
    {
        return $this->view($user, $adoptionRequest);
    }
}
