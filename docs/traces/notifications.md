# Trace: new chirp → `ChirpCreated` → queued listener → `NewChirp` mail

> **This trace describes the `v1-with-auth` request path.** None of it exists on `main` (ADR 0008): with no accounts, there is no `ChirpCreated` event, listener, `NewChirp` mail or sent ledger.

Laravel 13.38.0, Telescope 5.26.0 (from `composer.lock`). Paths are relative to
`vendor/laravel/framework/src/Illuminate/` unless they start with `app/`. Decisions: ADR 0007.

```
request (POST /chirps)        worker: listener job               worker: one job per recipient
ChirpController@store         CallQueuedHandler::call            CallQueuedHandler::call
  INSERT chirps                 unserialize -> SELECT chirp        unserialize -> SELECT chirp, users
  ChirpCreated::dispatch        CallQueuedListener::handle         WithoutOverlapping (cache lock)
  Dispatcher -> queueHandler    SendNewChirpNotifications          NotificationSender::sendNow
  INSERT jobs  ---------------->  chunkById(100) SELECT users        shouldSend -> SELECT ledger
                                  Notification::send(chunk)          MailChannel -> Markdown -> mailer
                                  INSERT jobs (per recipient) ---->  afterSending -> INSERT ledger
```

## 1. Dispatch (in the request)

`app/Http/Controllers/ChirpController.php` creates the chirp, then calls `ChirpCreated::dispatch($chirp)`.
`Foundation/Events/Dispatchable.php:13-16` is just `event(new static(...$arguments))`. The listener is
found by **event discovery**, not registration: `Foundation/Support/Providers/EventServiceProvider.php:146-159`
scans `app/Listeners` (`:166-171`) and maps `handle(ChirpCreated $event)` by its type hint.

`Events/Dispatcher.php:279` `dispatch()` → `invokeListeners()` (`:319`). Each listener was wrapped by
`makeListener()` (`:483`) → `createClassListener()` (`:509`) → `createClassCallable()` (`:528-541`), which
checks `handlerShouldBeQueued()` (`:569-577`: does the class implement `ShouldQueue`?) and returns
`createQueuedHandlerCallable()` (`:587-598`). Calling it runs `queueHandler()` (`:661`):

- `createListenerAndJob()` (`:731-738`) builds the listener **without its constructor** and wraps the event
  in a `CallQueuedListener`.
- `propagateListenerOptions()` (`:747-790`) copies the listener's settings onto the job: `backoff()`
  (`:758`), `deleteWhenMissingModels` (`:765`), `tries` (`:766`).
- `$connection->pushOn(...)` (`:708`) pushes it. `Queue/Queue.php:176-192` `createObjectPayload()` writes
  `maxTries` (`:182`), `backoff` (`:186`, imploded to `"10,60,300"`) and `deleteWhenMissingModels` (`:189`)
  into the JSON payload, and serializes the job as `command` (`:192`).

The event uses `SerializesModels`, so only the chirp's class, id and loaded relations go into the payload.
Factories and seeders never call the controller, so they never reach this point (ADR 0007).

## 2. The queued listener (worker)

`Queue/CallQueuedHandler.php:69-77` `call()` unserializes the command (`getCommand()`, `:113-124`). Restoring
the event's chirp runs `Queue/SerializesAndRestoresModelIdentifiers.php:108-112`:
`...->useWritePdo()->firstOrFail()->loadMissing($value->relations)`. **If the chirp was deleted**,
`firstOrFail` throws `ModelNotFoundException`, caught at `CallQueuedHandler.php:75-76` →
`handleModelNotFound()` (`:328-339`): with `deleteWhenMissingModels` in the payload the job is deleted;
without it, `$job->fail($e)` puts it in `failed_jobs`. The test "a chirp deleted before the fan-out runs"
asserts the first outcome.

Otherwise `dispatchThroughMiddleware()` (`:133`) → `Events/CallQueuedListener.php:145-152` `handle()`:
`$container->make($this->class)` and `$handler->handle(...$this->data)`.

`app/Listeners/SendNewChirpNotifications.php` queries other verified users with
`chunkById(100)`. `Database/Concerns/BuildsQueries.php:133` → `orderedChunkById()` (`:164`), which pages with
`forPageAfterId($limit, $lastId)` (`:191`): `where id > ? order by id limit 100`. That's keyset paging, so it's
safe while users sign up mid-loop, and memory stays at one chunk.

**Failure and retry.** If `handle()` throws, `Queue/Worker.php:675` `handleJobException()` releases the job
with `calculateBackoff()` (`:876-886`): `explode(',', $job->backoff())[$attempts - 1]`, so 10s, then 60s.
When the next attempt would exceed `maxTries`, `markJobAsFailedIfWillExceedMaxAttempts()` (`:788`) →
`failJob()` (`:864`) → `CallQueuedListener::failed()` (`:252-262`) calls our `failed($event, $e)`, which
logs. `Queue/Console/WorkCommand.php:425-427` writes the `failed_jobs` row.

## 3. Sending a notification to a chunk

`Notification::send($users, new NewChirp($chirp))` → `Notifications/ChannelManager.php:46-49` →
`NotificationSender::send()`; `Notifications/NotificationSender.php:90-91`: `NewChirp` is `ShouldQueue`, so
`queueNotification()` (`:225`). It loops **per notifiable and per channel** and dispatches
(`:286-297`) one `SendQueuedNotifications` holding a single user, the notification and `['mail']`, with
`->through($notification->middleware($notifiable, $channel))`. That's why one recipient's SMTP failure
never re-sends to anyone else.

`Notifications/SendQueuedNotifications.php:102-121` copies `tries` and `deleteWhenMissingModels` from
the notification. `backoff()` (`:178-187`) and `failed()` (`:167-172`) delegate to it at run time.

## 4. Delivering one notification (worker)

`CallQueuedHandler::call` again: the notification's chirp and the notifiable are `SerializesModels`
identifiers, so a deleted chirp or recipient is a `ModelNotFoundException` → deleted (not failed), as in
section 2. Then `dispatchThroughMiddleware()` runs `Queue/Middleware/WithoutOverlapping.php:71-86`: it takes
a cache lock on `new-chirp:{chirp}:{user}` (60s expiry, below `retry_after`; see ADR 0007). If another copy holds it and `releaseAfter` is null
(`dontRelease()`), the job just returns, and `CallQueuedHandler.php:100-102` deletes it.

`SendQueuedNotifications::handle()` (`:146-149`) → `ChannelManager::sendNow()` (`:59-62`) →
`NotificationSender::sendNow()` (`:105-126`) → `sendToNotifiable()` (`:155`):

1. `shouldSendNotification()` (`:206-216`) calls our `shouldSend()` (`:208`). If the ledger already has
   `(chirp, user)`, it fires `NotificationSkipped` and returns. Then it fires `NotificationSending` via
   `events->until` (`:213`). The idempotency test throws from a listener of that event to simulate an SMTP
   failure.
2. `$this->manager->driver('mail')->send(...)` (`:172`). `ChannelManager.php:111-114` resolves
   `Channels\MailChannel` from the container (which is why the retry test can mock it).
3. `afterSending()` (`:189-191`) runs only after a successful send. We insert the ledger row there.

## 5. The mail channel

`Notifications/Channels/MailChannel.php:53-70`: `toMail()` → `MailMessage`, then `mailer->send(buildView(...))`.
`buildMarkdownHtml()` (`:112-117`) renders `notifications::email` through `Mail/Markdown.php:75` `render()`.
Blade echoes become `EncodedHtmlString` (`htmlspecialchars`), so `<script>` becomes `&lt;script&gt;`.
That alone doesn't stop **Markdown injection**: a chirp `[Claim](https://evil.test)` would become a real
link, because the whole email is then run through CommonMark. `Markdown::withSecuredEncoding()` (set in
`AppServiceProvider::configureDefaults`) makes echoes also escape `\`, `[` and `]` (`:87-97`), so the
brackets stay literal text. The tests cover both.

## 6. SQL (captured from a throwaway test with `queue.default=database`, then deleted)

```sql
-- POST /chirps
insert into "chirps" ("message", "user_id", "updated_at", "created_at") values ('Hello SQL', 1, ...)
insert into "jobs" ("queue", "attempts", "reserved_at", "available_at", "created_at", "payload") values ('default', 0, null, ...)
-- worker: listener job
select * from "jobs" where "queue" = ? and (("reserved_at" is null and "available_at" <= ?) or ("reserved_at" <= ?)) order by "id" asc limit 1
update "jobs" set "reserved_at" = ?, "attempts" = 1 where "id" = 1
select * from "chirps" where "chirps"."id" = 1 limit 1                -- restore event model (firstOrFail)
select * from "users" where "users"."id" in (1)                       -- loadMissing('user')
select * from "users" where "users"."id" != 1 and "email_verified_at" is not null and "id" is not null order by "id" asc limit 100
insert into "jobs" (...) values ('default', 0, null, ...)             -- one SendQueuedNotifications per recipient
select * from "jobs" where "id" = 1 limit 1
delete from "jobs" where "id" = 1
-- worker: notification job
select * from "jobs" where ... limit 1
update "jobs" set "reserved_at" = ?, "attempts" = 1 where "id" = 2
select * from "chirps" where "chirps"."id" = 1 limit 1                -- notification's chirp
select * from "users" where "users"."id" in (1)                       -- its serialized 'user' relation
select * from "users" where "users"."id" in (2)                       -- the notifiable
select exists(select * from "sent_chirp_notifications" where "chirp_id" = 1 and "user_id" = 2) as "exists"
insert or ignore into "sent_chirp_notifications" ("chirp_id", "user_id", "sent_at") values (1, 2, ?)
select * from "jobs" where "id" = 2 limit 1
delete from "jobs" where "id" = 2
```

Fan-out costs one users query per 100 recipients plus one `jobs` insert per recipient. Each delivery costs
five reads and one ledger write. On Postgres, `insertOrIgnore` compiles to `insert ... on conflict do nothing`.

## 7. Telescope

`Foundation/PackageManifest.php:120-135` auto-registers every installed package's providers unless the root
`composer.json` lists it under `extra.laravel.dont-discover` (`:165`). So locally and in CI (dev
dependencies installed) `Laravel\Telescope\TelescopeServiceProvider` is discovered. Its `boot()` returns
early unless `config('telescope.enabled')`. Our `config/telescope.php` defaults that to `APP_ENV === 'local'`
(phpunit.xml forces `false`). `App\Providers\TelescopeServiceProvider` (gate, migrations) is registered by
`AppServiceProvider::registerTelescope()` only when `environment('local')` and the class exists. A
production install with `--no-dev` has no Telescope classes at all.
