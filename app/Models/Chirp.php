<?php

namespace App\Models;

use Database\Factories\ChirpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

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

    /**
     * Chirps live this long on the public board.
     */
    public static function lifetimeHours(): int
    {
        return Config::integer('chirper.lifetime_hours');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('created_at', '>', now()->subHours(self::lifetimeHours()));
    }

    public static function pruneExpired(): void
    {
        static::query()->where('created_at', '<=', now()->subHours(self::lifetimeHours()))->delete();
    }

    /**
     * Expired chirps 404 on edit/update/delete, even before they're pruned.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->resolveRouteBindingQuery(static::query()->live(), $value, $field)->first();
    }
}
