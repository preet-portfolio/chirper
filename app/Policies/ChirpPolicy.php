<?php

namespace App\Policies;

use App\Models\Chirp;
use App\Models\User;

class ChirpPolicy
{
    /**
     * Only the author may edit their chirp.
     */
    public function update(User $user, Chirp $chirp): bool
    {
        return $chirp->user()->is($user);
    }

    /**
     * Only the author may delete their chirp.
     */
    public function delete(User $user, Chirp $chirp): bool
    {
        return $chirp->user()->is($user);
    }
}
