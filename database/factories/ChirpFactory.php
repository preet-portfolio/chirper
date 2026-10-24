<?php

namespace Database\Factories;

use App\Models\Chirp;
use App\Support\AuthorToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Chirp>
 */
class ChirpFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message' => fake()->sentence(),
            'author_token_hash' => AuthorToken::hash(Str::random(40)),
        ];
    }

    public function ownedBy(string $token): static
    {
        return $this->state(['author_token_hash' => AuthorToken::hash($token)]);
    }
}
