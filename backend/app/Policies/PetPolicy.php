<?php

namespace App\Policies;

use App\Models\Pet;
use App\Models\User;

class PetPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, Pet $pet): bool
    {
        return filled($user->organization_id)
            && (string) $user->organization_id === (string) $pet->organization_id;
    }

    public function update(User $user, Pet $pet): bool
    {
        return $this->view($user, $pet);
    }

    public function delete(User $user, Pet $pet): bool
    {
        return $this->view($user, $pet);
    }
}
