<?php

namespace App\Support;

use App\Models\Chirp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Anonymous ownership: the posting browser holds a random token in an encrypted,
 * http-only cookie, and chirps store only its SHA-256 hash. Owning a chirp means
 * presenting the token whose hash matches (ADR 0008).
 */
class AuthorToken
{
    public const COOKIE = 'chirper_author';

    public function __construct(private Request $request) {}

    public function current(): ?string
    {
        $token = $this->request->cookie(self::COOKIE);

        return is_string($token) && strlen($token) === 40 ? $token : null;
    }

    public function currentOrNew(): string
    {
        return $this->current() ?? Str::random(40);
    }

    /**
     * Keep the token longer than a chirp lives.
     */
    public static function cookieMinutes(): int
    {
        return Config::integer('chirper.author_cookie_days') * 24 * 60;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function owns(Chirp $chirp): bool
    {
        $token = $this->current();

        return $token !== null && hash_equals($chirp->author_token_hash, self::hash($token));
    }
}
