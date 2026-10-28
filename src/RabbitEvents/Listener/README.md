# RabbitEvents Listener

The RabbitEvents Listener component provides an API for handling events published across the application structure. More information is available in the [RabbitEvents documentation](https://github.com/rabbitevents/rabbitevents).

If you only need to handle events, you can use the RabbitEvents `Listener` separately from the main library.

## Table of Contents

1. [Installation via Composer](#installation)
2. [Configuration](#configuration)
3. [Register a Listener](#register)
   - [Registering Listeners via Attributes](#registering-listeners-via-attributes)
   - [Listener Discovery](#listener-discovery)
4. [Defining Listeners](#defining-listeners)
   - [Handling Protobuf Messages](#handling-protobuf-messages)
5. [Middleware](#listener-middleware)
   - [Pipeline Middleware (Onion-style)](#pipeline-middleware-onion-style)
6. [Stopping The Propagation Of An Event](#stopping-propagation)
7. [Reliability](#reliability)
   - [Retries](#retries)
   - [Failed Messages](#failed-messages)
   - [Kill Switch](#kill-switch)
   - [Deduplication](#deduplication)
8. [Console Commands](#commands)
   - [Command `rabbitevents:listen`](#command-listen)
     - [Options](#listen-options)
   - [Failed Message Commands](#failed-commands)
   - [Command `rabbitevents:list`](#command-list)
   - [Event Discovery Caching](#discovery-caching)
9. [Supervisor Configuration](#supervisor)
10. [Logging & Observability](#logging)

## Installation via Composer<a name="installation"></a>

RabbitEvents Listener can be installed via the Composer package manager:

```bash
composer require rabbitevents/listener
```

After installing Listener, you can execute the `rabbitevents:install` Artisan command, which will install the RabbitEvents configuration file and `RabbitEventsServiceProvider` into your application:

```bash
php artisan rabbitevents:install
```

## Configuration <a name="configuration"></a>

Details about the configuration are described in the library [documentation](https://github.com/rabbitevents/rabbitevents#configuration).

## Register a Listener <a name="register"></a>

The `listen` property of `RabbitEventsServiceProvider` contains an array of all events (keys) and their listeners (values). You can add as many events to this array as your application requires.

You can even register listeners using the `*` as a wildcard parameter, allowing you to catch multiple events with the same listener. Wildcard listeners receive the event name as their first argument and the entire event data array as their second argument (an event published with the `publish()` helper arrives wrapped into a list: `[$payload]`). Closure listeners are called the same way, with the event name first.

```php
<?php

use RabbitEvents\Listener\ListenerServiceProvider;

class RabbitEventsServiceProvider extends ListenerServiceProvider
{
    protected array $listen = [
        'payment.succeeded' => [
            'App\Listeners\SendNotification',
            'App\Listeners\ChangeUserRole@process',
            ['App\Listeners\ChangeUserRole', 'process'],
        ],
        'item.*' => [
            AllItemEventsListener::class,
        ],
    ];
}
```

A closure can't be a property default in PHP; register closure listeners with the `RabbitEvents` facade, e.g. in the `boot()` method of a service provider:

```php
<?php

use Illuminate\Support\Facades\Log;
use RabbitEvents\Listener\Facades\RabbitEvents;

// A closure gets the event name first, then the payload.
RabbitEvents::listen('payment.succeeded', function (string $event, array $payload) {
    Log::info('Payment Succeeded', $payload);
});
```

### Registering Listeners via Attributes<a name="registering-listeners-via-attributes"></a>

Instead of manually registering listeners in the `$listen` array, you can use the `#[Listener]` attribute on your listener class or methods.

**Class Listener**

```php
use RabbitEvents\Listener\Attributes\Listener;

#[Listener(event: 'payment.succeeded')]
class SendNotification
{
    public function handle($payload)
    {
        // ...
    }
}
```

**Method Listeners**

```php
class UserEventSubscriber
{
    #[Listener(event: 'user.created')]
    public function onUserCreated($payload) {}

    #[Listener(event: 'user.deleted')]
    public function onUserDeleted($payload) {}
}
```

To enable auto-discovery, ensure you are using the `RabbitEventsServiceProvider`.

### Listener Discovery <a name="listener-discovery"></a>

Listener discovery is **enabled by default**. The service provider will automatically scan your `app/Listeners` directory for classes with the `#[Listener]` attribute.

If you wish to **disable** auto-discovery, you can override the `shouldDiscoverEvents` method in your `RabbitEventsServiceProvider`:

```php
/**
 * Determine if events and listeners should be automatically discovered.
 */
public function shouldDiscoverEvents(): bool
{
    return false;
}
```

By default, the `listenerDirectory` is set to `app_path('Listeners')`. You can override this method if your listeners are located elsewhere.

```php
/**
 * Get the listener directory path.
 */
protected function listenerDirectory(): string
{
    return app_path('Bus/Listeners');
}
```

When discovery is enabled, the service provider will scan the directory for classes with the `#[Listener]` attribute and register them automatically.

## Defining Listeners <a name="defining-listeners"></a>

Event listeners receive the event data in the method provided in the `$listen` definition. If no method is provided, the `handle` method will be called. Within the handle method, you can perform actions necessary to respond to the event:

**Example of The Event Listener class**

```php
<?php

class SendNotification
{
    public function handle($eventPayload)
    {
        Mailer::to(Arr::get($eventPayload, 'user.email'))
            ->subject('Payment Succeeded')
            ->message('...');
    }
}
```

**Example of The Wildcard Event Listener class**

```php
<?php

class AllItemEventsListener
{
    public function handle($event, $payload)
    {
        match ($event) {
            'item.created' => $this->created($payload),
            'item.deleted' => $this->deleted($payload),
            default => null,
        };
    }
}
```

### Handling Protobuf Messages<a name="handling-protobuf-messages"></a>

If you publish a Protobuf message, the Listener will automatically receive the hydrated object instance.
> **Note**: You must install the `rabbitevents/protobuf` package to use this feature.

```php
<?php

use App\Messages\AccountCreated;

class AccountListener
{
    public function handle(AccountCreated $message)
    {
        // $message is an instance of App\Messages\AccountCreated
        $id = $message->getId();
    }
}
```

## Middleware <a name="listener-middleware"></a>

A Listener middleware allows you to wrap custom logic around the execution of a listener, reducing boilerplate in the handlers themselves. For example, you have an event `charge.succeeded` which can be handled in several APIs but only if this payment is for a specific entity.

Without middleware, you had to check the entity type in a listener's handle method.

```php
<?php

/**
 * Handle payment but only if the type is 'mytype'
 *
 * @param array $payload
 * @return void
 */ 
public function handle($payload) 
{
    if (\Arr::get($payload, 'entity.type') !== 'mytype') {
        return;
    }   
    
    Entity::find(\Arr::get($payload, 'entity.id'))->activate();
}
```

It is okay if you have only one listener. What if many listeners must implement the same check at the first line of the `handle` method? The code will become a bit noisy.

Instead of writing the same check at the start of each listener, you could define listener middleware that handles an entity type.

```php
<?php

namespace App\Listeners\RabbitEvents\Middleware;

class FilterEntities
{
    public function handle(array $payload): bool
    {
        return \Arr::get($payload, 'entity.type') === 'mytype';
    }
}
```

A middleware gets the payload the way a regular listener gets it, after the event name for a wildcard listener (`handle(string $event, array $payload)`).

In this example, you see that if you need to stop the propagation, just return `false`.

After creating listener middleware, they can be attached to a listener by returning them from the listener's `middleware` method or as an array item from the `$middleware` attribute.

```php
<?php

use App\Listeners\RabbitEvents\Middleware\FilterEntities;

class PaymentListener
{
    public array $middleware = [
        FilterEntities::class,
        'App\Listeners\RabbitEvents\Middleware\AnotherMiddleware@someAction',
    ];      

    public function middleware(array $payload): bool
    {
        return \Arr::get($payload, 'entity.type') === 'mytype';
    }
}
```

You can see that you are also able to specify which method of the attached middleware class should be called.

### Pipeline Middleware (Onion-style)

In addition to filter middleware, RabbitEvents supports Laravel's onion-style pipeline middleware accepting a `$next` closure as the last argument. `$next` takes the payload (without the event name of a wildcard listener):

```php
<?php

namespace App\Listeners\RabbitEvents\Middleware;

use Closure;

class MeasureExecutionTime
{
    public function handle($payload, Closure $next)
    {
        $start = hrtime(true);

        $response = $next($payload);

        $elapsed = (hrtime(true) - $start) / 1e6;
        \Log::info("Execution took {$elapsed}ms");

        return $response;
    }
}
```

## Stopping The Propagation Of An Event <a name="stopping-propagation"></a>

Sometimes, you may wish to stop the propagation of an event to other listeners. You can do so by returning `false` from your listener's handle method.

# Reliability <a name="reliability"></a>

RabbitEvents relies only on RabbitMQ: no database tables, Redis instances or migrations are required.

## Retries <a name="retries"></a>

When a listener throws, the message is put back into its queue and tried again after `--sleep` seconds, until the listener has been tried `--tries` times. A listener class can set its own limits, as Laravel queued listeners do:

```php
class SendInvoice
{
    // Or a tries() method. 0 means unlimited.
    public int $tries = 5;

    // Or a backoff() method returning an int or an array
    public array $backoff = [1, 5, 30];

    public function handle(array $payload): void
    {
        // ...
    }
}
```

Or with attributes:

```php
use RabbitEvents\Listener\Attributes\Backoff;
use RabbitEvents\Listener\Attributes\Tries;

#[Tries(5)]
#[Backoff(1, 5, 30)]
class SendInvoice
{
    public function handle(array $payload): void
    {
        // ...
    }
}
```

- `$tries`, `tries()` or `#[Tries]` overrides `--tries` for this listener.
- `$backoff`, `backoff()` or `#[Backoff]` overrides `--sleep`: an `int` is the delay before every retry; an array gives the delay after the first, second, ... attempt, and its last value applies to the rest (`[1, 5, 30]` waits 1s, 5s, then 30s for every further retry). An empty array falls back to `--sleep`.
- Laravel's `Illuminate\Queue\Attributes\Tries` and `Backoff` work as well, when `illuminate/queue` provides them.
- If a listener declares an option in several ways, as in Laravel, the method wins, then the attribute, then the property. An attribute can be set on the class, on a trait it uses or on a parent class. A subclass that redeclares the property overrides the attribute of its parent.
- As in Laravel, they are read from the class without calling its constructor, so no dependencies are resolved for them and a value set in the constructor is ignored. `tries()` and `backoff()` get the same arguments as the handler method. A `[$object, 'method']` or invokable object listener is read from that object.
- Tries are counted **per listener**, in the `x-listener-attempts` property: if two listeners handle a message and the first one fails once, the second one still gets all its tries. Methods of one subscriber class share its `$tries`/`$backoff` but count their attempts separately.
- Closures can't be identified across deliveries: they count the deliveries of the message (`x-attempts`), which include the retries of other listeners of the message, and use `--tries` and `--sleep`. A closure is never failed before it runs: when it throws on a delivery past `--tries`, the message fails.
- `failed()` is called on the listener instance that handled the message, so it sees the state `handle()` left (Laravel calls it on a new instance).
- Each distinct delay gets its own delay queue in RabbitMQ (created on first use). Transports that can't delay a delivery (Redis) retry right away.
- To fail without retrying, throw `RabbitEvents\Listener\Exceptions\FailedException`.

## Failed Messages <a name="failed-messages"></a>

A message fails when a listener exhausts its tries (`--tries` or [its own](#retries)), exceeds the timeout (`--timeout`), or throws `RabbitEvents\Listener\Exceptions\FailedException`. RabbitEvents then moves it to the `{queue}.failed` queue instead of dropping it:

- `{queue}.failed` is a plain durable queue declared by `rabbitevents:listen` next to the listener queue. The listener queue itself is declared exactly as in 9.x, without extra arguments, so 9.x and 10.x workers can consume the same queue.
- The failed message keeps its payload, `message_id` and `trace_context` and gets these properties: `x-failed-at`, `x-original-routing-key`, `x-original-queue`, `x-exception-class`, `x-exception-message`, `x-exception-trace` (message and trace are cut to 4 KB to fit into one AMQP frame) and `x-listener`.
- Messages published by RabbitEvents 9.x have no `message_id`. They get one when moved to `{queue}.failed`, so they can be retried or deleted by ID.
- Listeners that already succeeded on a message are recorded in `x-succeeded-listeners` and are skipped when the message is retried, both after a listener released it and after `rabbitevents:retry`. Listeners should still be idempotent. `rabbitevents:retry` resets the attempts, so every listener gets all its tries again.

### Interrupted processing <a name="interrupted-messages"></a>

If processing throws outside the listeners (e.g. in a custom `Deduplicator`), the message is not acknowledged as handled. It is put back into its queue the same way a failed listener releases it (after `--sleep`), with `x-succeeded-listeners`, so the listeners that already handled it skip it. On its `--tries`th delivery it is moved to `{queue}.failed` instead, so that a permanent failure outside the listeners can't loop forever. Only if the message can't be put back is the original returned to RabbitMQ unchanged, and then its listeners run again.

If the worker can neither release a message nor move it to `{queue}.failed` (publishing fails), it returns the original to the queue and stops with exit code 1, and so does a worker without a releaser whose message can only be returned. (When RabbitMQ itself refuses the publish, for example a delay queue declared with other arguments, it closes the worker's channel: the worker exits with that exception, and RabbitMQ returns the unacknowledged message.) The original carries no record of this delivery, and RabbitMQ gives it back to the same worker at once: a worker that went on would handle it again and again, and `--tries` could never stop it. Your process manager (Supervisor, systemd, Kubernetes) restarts the worker; configure a restart delay.

Manage failed messages with the [failed message commands](#failed-commands). To drop failed messages instead, as 9.x did, set `RABBITEVENTS_FAILED_QUEUE_ENABLED=false` (`rabbitevents.failed_queue.enabled`).

## Kill Switch <a name="kill-switch"></a>

When a database or an API the listeners need is down, every message fails. With `--tries=1` the worker moves the whole queue to `{queue}.failed` within minutes. The kill switch stops the worker from consuming instead, until the dependency is likely back:

- It trips when at least `trip_threshold` percent of the messages the worker handled in the last `tracking_period` seconds failed, once it handled `activation_threshold` messages in that period. A failed message is one whose listener threw, whether it was released for another try or moved to `{queue}.failed`.
- Then the worker consumes nothing for `restart_timeout` seconds. The messages stay ready in the queue: with RabbitMQ the worker fetches messages one by one, so it holds none unacknowledged, and other workers can take them. (The Redis transport may have read one more message into its buffer: it stays pending for this worker until the pause ends.) The message that tripped the switch was already released or failed as usual.
- After the pause, the first message is a probe: if it fails, the switch trips again right away; if it succeeds, the worker goes back to counting.
- Each worker decides on its own, from the messages it handled itself; no storage is needed. A worker that has to stop anyway (`SIGTERM`, `--memory` exceeded) stops instead of pausing, and `SIGTERM` stops a paused worker at once.

It is disabled by default. Enable it in `config/rabbitevents.php` or with environment variables:

```dotenv
RABBITEVENTS_KILL_SWITCH_ENABLED=true
RABBITEVENTS_KILL_SWITCH_ACTIVATION_THRESHOLD=10 # messages
RABBITEVENTS_KILL_SWITCH_TRIP_THRESHOLD=15       # percent of failed messages
RABBITEVENTS_KILL_SWITCH_TRACKING_PERIOD=60      # seconds
RABBITEVENTS_KILL_SWITCH_RESTART_TIMEOUT=60      # seconds
```

The worker dispatches `RabbitEvents\Listener\Events\KillSwitchTripped` (with `$restartTimeout`) and `KillSwitchRestarted`; listen to them for alerts. If you enable AMQP heartbeats (`RABBITEVENTS_HEARTBEAT`), keep `restart_timeout` below twice the heartbeat: a paused worker doesn't talk to the broker, which then closes the connection, and the worker exits for Supervisor to restart it.

## Deduplication <a name="deduplication"></a>

RabbitMQ delivers a message *at least once*: when it doesn't get the acknowledgement (the worker lost its connection or was killed), it delivers the message again, usually to another worker. RabbitEvents doesn't deduplicate such redeliveries by itself, because recognizing them needs storage shared by the workers, and RabbitEvents relies on RabbitMQ only. Write listeners so that handling a message twice is safe, e.g. by checking a business key.

If your service has a cache store shared by its workers, install [`rabbitevents/deduplication`](https://github.com/rabbitevents/deduplication): it skips messages redelivered after they were handled. Its README lists exactly which cases it covers.

You can also bind your own implementation of `RabbitEvents\Listener\Contracts\Deduplicator` in a service provider. `isDuplicate()` is called before the listeners and `markHandled()` after all of them succeeded, both with the queue name, because the same message legitimately reaches every queue bound to its event:

```php
$this->app->singleton(\RabbitEvents\Listener\Contracts\Deduplicator::class, MyDeduplicator::class);
```

If one of them throws, the message is treated as [interrupted](#interrupted-messages).

# Console Commands <a name='commands'></a>

## Command `rabbitevents:listen` <a name='command-listen'></a>

There is a command that registers events in RabbitMQ:

```bash
php artisan rabbitevents:listen event.name --memory=512 --tries=3 --sleep=5 -v
```

This command registers a separate queue in RabbitMQ bound to an event. As `rabbitevents:listen` registers a queue, you should run this command before you start publishing your events. Nothing will happen if you publish an event first, but it will not be handled by a Listener without the first run.

To start listening to all events registered in the application, you can run without any event names:

```bash
php artisan rabbitevents:listen
```

or mention events separated by commas:

```bash
php artisan rabbitevents:listen event.one,event.two,event.n
```

If the list of events is too long, the queue name will be created as an MD5 checksum of the lengthy queue name. 
This was implemented because the AMQP driver does not accept longer names.

You can start listening to an event only by using the `rabbitevents:listen` command, so you could use some system such as [Supervisor](http://supervisord.org/) or [pm2](http://pm2.keymetrics.io/) to control your listeners.

### Options<a name="listen-options"></a>

`--service=` - By default, it's `config('app.name')`. When a queue starts, the name of the service becomes a part of a queue name: `service:event.name`. You could override the first part of a queue name by this option.\
`--memory=128` - The memory limit in megabytes. The RabbitEvents worker restarts if the limit is exceeded.\
`--timeout=60` - The length of time (in seconds) each Message should be allowed to be handled.\
`--tries=1` - The number of times to attempt a listener before moving the Message to the [failed queue](#failed-messages) (`{queue}.failed`), unless the listener sets [its own `$tries`](#retries).\
`--sleep=5` - How long in seconds to wait before the next try of a listener that failed, unless it sets [its own `$backoff`](#retries).\
`--quiet` - Hide console output.\
`-v` - Verbosity. Enables a stack trace for Exceptions.

## Failed Message Commands <a name="failed-commands"></a>

All commands resolve the failed queue the same way `rabbitevents:listen` resolves its queue, from `--service` (default `config('app.name')`) and `--events` (default: all registered events), or take it from `--queue` (with or without the `.failed` suffix). `--connection` selects the connection.

```bash
# List failed messages: ID, event, failure time and exception (--limit=50 by default)
php artisan rabbitevents:failed
php artisan rabbitevents:failed --events="order.created,order.shipped"
php artisan rabbitevents:failed --queue=my-service:order.created

# Move a failed message back to its original queue, with attempts reset
php artisan rabbitevents:retry 9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d
php artisan rabbitevents:retry --all --events="order.created"

# Delete one failed message, or all of them
php artisan rabbitevents:forget 9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d
php artisan rabbitevents:flush --events="order.created"
```

`failed`, `retry <id>` and `forget <id>` read up to 10,000 messages and keep them unacknowledged until they finish, then return the messages they didn't touch to the queue in the original order. `retry --all` and `flush` read the whole queue. Any scan stops when it reaches a message it has already seen, e.g. a retried message that failed again.

## Command `rabbitevents:list` <a name='command-list'></a>

To get the list of all registered events, please use the command `rabbitevents:list`.

```bash
php artisan rabbitevents:list
```

## Event Discovery Caching <a name="discovery-caching"></a>

If you are using event discovery, scanning the listener directory on every request (or worker boot) can be slow in large applications. You can cache the discovered events using the `rabbitevents:cache` command:

```bash
php artisan rabbitevents:cache
```

This command will generate a `bootstrap/cache/rabbitevents.php` file. The `RabbitEventsServiceProvider` will automatically use this file instead of scanning the directory.

To clear the cache, use the `rabbitevents:clear` command:

```bash
php artisan rabbitevents:clear
```

> [!IMPORTANT]
> You should run `rabbitevents:cache` during your deployment process.

# Supervisor Configuration<a name="supervisor"></a>

The Supervisor configuration is similar to [Laravel Queue](https://laravel.com/docs/queues#supervisor-configuration).

# Logging & Observability <a name='logging'></a>

The package provides 2 ways to see what happens to your listener:
1. **Console Output**: Outputs color-coded logs including the unique Message ID and execution duration (in milliseconds):
   ```
   [9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d] [2026-10-20 12:00:00] Processing: payment.succeeded:App\Listeners\SendNotification@handle
   [9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d] [2026-10-20 12:00:00] Processed:  payment.succeeded:App\Listeners\SendNotification@handle (12.45ms)
   ```
   To get exception stack traces, run with verbosity >= 1 (`-v`). To suppress output, use `--quiet`.

2. **Application Logger**: Set `rabbitevents.logging.enabled` to `true` to send events directly to your application log channel (`rabbitevents.logging.channel`).

