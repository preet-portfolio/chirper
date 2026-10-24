<?php

namespace App\Notifications;

use App\Models\Chirp;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "New Chirp from {author}" email. Queued as one job per recipient, and idempotent per
 * (chirp, recipient) through the sent_chirp_notifications ledger (ADR 0007).
 */
class NewChirp extends Notification implements ShouldQueue
{
    use Queueable;

    public const string LEDGER = 'sent_chirp_notifications';

    public int $tries = 3;

    /**
     * Seconds one attempt may run. It must be shorter than OVERLAP_LOCK_SECONDS, which must in turn be
     * shorter than the queue's retry_after (90s). Then a worker that dies mid-send leaves a lock that
     * expires before its job is redelivered, so the redelivery sends instead of being dropped.
     */
    public int $timeout = 30;

    public const int OVERLAP_LOCK_SECONDS = 60;

    /** A chirp (or recipient) deleted before delivery means the email is no longer wanted. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Chirp $chirp) {}

    public static function overlapKey(Chirp $chirp, User $recipient): string
    {
        return "new-chirp:{$chirp->id}:{$recipient->id}";
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * A second copy of this job for the same chirp and recipient is dropped while the first runs.
     * The first keeps its own retries, so dropping (not releasing) the copy loses nothing.
     *
     * @return list<object>
     */
    public function middleware(User $notifiable, string $channel): array
    {
        return [
            (new WithoutOverlapping(self::overlapKey($this->chirp, $notifiable)))
                ->dontRelease()
                ->expireAfter(self::OVERLAP_LOCK_SECONDS),
        ];
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        return DB::table(self::LEDGER)
            ->where('chirp_id', $this->chirp->id)
            ->where('user_id', $notifiable->id)
            ->doesntExist();
    }

    public function afterSending(User $notifiable, string $channel, mixed $response): void
    {
        DB::table(self::LEDGER)->insertOrIgnore([
            'chirp_id' => $this->chirp->id,
            'user_id' => $notifiable->id,
            'sent_at' => now(),
        ]);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $author = $this->chirp->user->name;

        return (new MailMessage)
            ->subject("New Chirp from {$author}")
            ->greeting("{$author} just chirped")
            ->line($this->chirp->message)
            ->action('Read it on Chirper', route('home'));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Sending a new-chirp notification failed.', [
            'chirp_id' => $this->chirp->id,
            'exception' => $exception,
        ]);
    }
}
