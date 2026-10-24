<?php

use App\Events\ChirpCreated;
use App\Listeners\SendNewChirpNotifications;
use App\Models\User;
use App\Notifications\NewChirp;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Mailer\SentMessage;

/**
 * Addresses of every email that reached the array mail transport, one entry per email.
 *
 * @return list<string>
 */
function emailedAddresses(): array
{
    return app('mailer')->getSymfonyTransport()->messages()
        ->map(fn (SentMessage $message) => $message->getOriginalMessage()->getTo()[0]->getAddress())
        ->values()
        ->all();
}

test('end to end: posting a chirp emails every other verified user exactly once', function () {
    $author = User::factory()->create(['name' => 'Ada']);
    $first = User::factory()->create();
    $second = User::factory()->create();
    User::factory()->unverified()->create();

    $this->actingAs($author)->post(route('chirps.store'), ['message' => 'Hello inboxes']);

    expect(emailedAddresses())->toEqualCanonicalizing([$first->email, $second->email]);
    $message = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    expect($message->getSubject())->toBe('New Chirp from Ada')
        ->and($message->getHtmlBody())->toContain('Hello inboxes');
});

test('re-running the fan-out after a partial failure does not email anyone twice', function () {
    $author = User::factory()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $chirp = $author->chirps()->create(['message' => 'Exactly once']);

    $failSecondOnce = true;
    Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$failSecondOnce, $second) {
        if ($failSecondOnce && $event->notifiable->is($second)) {
            $failSecondOnce = false;

            throw new RuntimeException('SMTP hiccup');
        }
    });

    $listener = app(SendNewChirpNotifications::class);
    expect(fn () => $listener->handle(new ChirpCreated($chirp)))->toThrow(RuntimeException::class, 'SMTP hiccup');
    expect(emailedAddresses())->toBe([$first->email]);

    $listener->handle(new ChirpCreated($chirp)); // the queue retries the listener
    $listener->handle(new ChirpCreated($chirp)); // and a stray duplicate job runs too

    expect(emailedAddresses())->toEqualCanonicalizing([$first->email, $second->email]);
});

test('a notification job is dropped, not retried, while another copy for the same chirp and recipient is running', function () {
    config(['queue.default' => 'database']);
    $author = User::factory()->create();
    $busy = User::factory()->create();
    $free = User::factory()->create();
    $chirp = $author->chirps()->create(['message' => 'Concurrent']);

    $lockKey = (new WithoutOverlapping(NewChirp::overlapKey($chirp, $busy)))
        ->getLockKey(new SendQueuedNotifications($busy, new NewChirp($chirp)));
    Cache::lock($lockKey, 60)->get();

    app(SendNewChirpNotifications::class)->handle(new ChirpCreated($chirp));
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect(emailedAddresses())->toBe([$free->email])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('a lock orphaned by a crashed worker expires before the job is redelivered, so the email is still sent', function () {
    config(['queue.default' => 'database']);
    $author = User::factory()->create();
    $recipient = User::factory()->create();
    $chirp = $author->chirps()->create(['message' => 'Survive a crash']);

    // Take the lock exactly as the middleware would, then never release it, as a dead worker wouldn't.
    $overlap = collect((new NewChirp($chirp))->middleware($recipient, 'mail'))->sole();
    $lockKey = $overlap->getLockKey(new SendQueuedNotifications($recipient, new NewChirp($chirp)));
    Cache::lock($lockKey, $overlap->expiresAfter)->get();

    app(SendNewChirpNotifications::class)->handle(new ChirpCreated($chirp));
    $this->travel(config('queue.connections.database.retry_after'))->seconds(); // redelivery time
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect(emailedAddresses())->toBe([$recipient->email])
        ->and(NewChirp::OVERLAP_LOCK_SECONDS)->toBeLessThan(config('queue.connections.database.retry_after'))
        ->and((new NewChirp($chirp))->timeout)->toBeLessThan(NewChirp::OVERLAP_LOCK_SECONDS);
});

test('a chirp deleted before the fan-out runs emails nobody and the job is discarded, not failed', function () {
    config(['queue.default' => 'database']);
    $author = User::factory()->create();
    User::factory()->create();

    $this->actingAs($author)->post(route('chirps.store'), ['message' => 'Regret']);
    $author->chirps()->sole()->delete();
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect(emailedAddresses())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('a chirp deleted after the fan-out but before delivery emails nobody and the jobs are discarded, not failed', function () {
    config(['queue.default' => 'database']);
    $author = User::factory()->create();
    User::factory()->count(2)->create();

    $this->actingAs($author)->post(route('chirps.store'), ['message' => 'Regret']);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
    expect(DB::table('jobs')->count())->toBe(2);

    $author->chirps()->sole()->delete();
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect(emailedAddresses())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});
