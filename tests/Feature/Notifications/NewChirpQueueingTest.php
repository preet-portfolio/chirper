<?php

use App\Events\ChirpCreated;
use App\Listeners\SendNewChirpNotifications;
use App\Models\User;
use App\Notifications\NewChirp;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/** Process exactly one job from the database queue, as a worker would. */
function workOneDatabaseJob(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
}

test('the fan-out listener is queued with retries, backoff and delete-when-missing', function () {
    Queue::fake();
    $author = User::factory()->create();

    $this->actingAs($author)->post(route('chirps.store'), ['message' => 'Queued']);

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SendNewChirpNotifications::class
        && $job->tries === 3
        && $job->backoff === [10, 60, 300]
        && $job->deleteWhenMissingModels === true);
});

test('each recipient gets their own queued notification job with retries, backoff and delete-when-missing', function () {
    Queue::fake();
    $author = User::factory()->create();
    $recipients = User::factory()->count(2)->create();
    $chirp = $author->chirps()->create(['message' => 'Queued']);

    app(SendNewChirpNotifications::class)->handle(new ChirpCreated($chirp));

    Queue::assertPushed(SendQueuedNotifications::class, 2);
    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof NewChirp
        && $job->notifiables->count() === 1
        && $job->channels === ['mail']
        && $job->tries === 3
        && $job->timeout === 30
        && $job->backoff() === [10, 60, 300]
        && $job->deleteWhenMissingModels === true
        && collect($job->middleware)->contains(fn ($middleware) => $middleware instanceof WithoutOverlapping));
    foreach ($recipients as $recipient) {
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notifiables->sole()->is($recipient));
    }
});

test('a failing mail is retried on the backoff schedule, then fails and is logged', function () {
    config(['queue.default' => 'database']);
    Log::spy();
    $this->mock(MailChannel::class)->shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));
    $author = User::factory()->create();
    User::factory()->create();
    $this->actingAs($author)->post(route('chirps.store'), ['message' => 'Retry me']);
    $chirp = $author->chirps()->sole();

    workOneDatabaseJob(); // the fan-out listener queues one notification job

    foreach ([10, 60] as $attempt => $delay) {
        workOneDatabaseJob();
        $job = DB::table('jobs')->sole();
        expect($job->attempts)->toBe($attempt + 1)
            ->and($job->available_at)->toBe(now()->getTimestamp() + $delay);
        $this->travel($delay + 1)->seconds();
    }

    workOneDatabaseJob(); // third and last attempt

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => $message === 'Sending a new-chirp notification failed.'
            && $context['chirp_id'] === $chirp->id
            && $context['exception']->getMessage() === 'SMTP down')
        ->once();
});

test('a fan-out that fails for good is logged with the chirp', function () {
    Log::spy();
    $chirp = User::factory()->create()->chirps()->create(['message' => 'Failed']);

    (new SendNewChirpNotifications)->failed(new ChirpCreated($chirp), new RuntimeException('Database gone'));

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => $message === 'Fanning out new-chirp notifications failed.'
            && $context['chirp_id'] === $chirp->id
            && $context['exception']->getMessage() === 'Database gone')
        ->once();
});
