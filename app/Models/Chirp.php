<?php

namespace App\Models;

use Database\Factories\ChirpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $message
 * @property string $author_token_hash SHA-256 of the posting browser's token (App\Support\AuthorToken)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['message'])]
#[Hidden(['author_token_hash'])]
class Chirp extends Model
{
    /** @use HasFactory<ChirpFactory> */
    use HasFactory;

    /** Chirps live this long on the public board. */
    public const LIFETIME_HOURS = 24;

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('created_at', '>', now()->subHours(self::LIFETIME_HOURS));
    }

    public static function pruneExpired(): void
    {
        static::query()->where('created_at', '<=', now()->subHours(self::LIFETIME_HOURS))->delete();
    }

    /**
     * Expired chirps 404 on edit/update/delete, even before they're pruned.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return static::query()->live()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}
