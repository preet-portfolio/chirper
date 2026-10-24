<?php

namespace App\Policies;

use App\Models\Chirp;
use App\Models\User;
use App\Support\AuthorToken;

/**
 * There are no accounts, so `$user` is always null; ownership is the browser's author token.
 */
class ChirpPolicy
{
    public function __construct(private AuthorToken $author) {}

    public function update(?User $user, Chirp $chirp): bool
    {
        return $this->author->owns($chirp);
    }

    public function delete(?User $user, Chirp $chirp): bool
    {
        return $this->author->owns($chirp);
    }
}
