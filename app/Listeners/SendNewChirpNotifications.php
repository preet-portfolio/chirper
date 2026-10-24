<?php

namespace App\Listeners;

use App\Events\ChirpCreated;
use App\Models\User;
use App\Notifications\NewChirp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Fans a new chirp out to every other verified user, one queued NewChirp job per recipient.
 *
 * Safe to run more than once: NewChirp skips recipients already in the sent ledger (ADR 0007).
 */
class SendNewChirpNotifications implements ShouldQueue
{
    public const int CHUNK_SIZE = 100;

    public int $tries = 3;

    /** The chirp may be deleted before this runs; then there is nothing to announce. */
    public bool $deleteWhenMissingModels = true;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(ChirpCreated $event): void
    {
        $chirp = $event->chirp->loadMissing('user');

        User::query()
            ->whereKeyNot($chirp->user_id)
            ->whereNotNull('email_verified_at')
            ->chunkById(self::CHUNK_SIZE, function (Collection $recipients) use ($chirp): void {
                Notification::send($recipients, new NewChirp($chirp));
            });
    }

    public function failed(ChirpCreated $event, Throwable $exception): void
    {
        Log::error('Fanning out new-chirp notifications failed.', [
            'chirp_id' => $event->chirp->id,
            'exception' => $exception,
        ]);
    }
}
