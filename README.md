# RabbitEvents

[![Tests Status](https://github.com/rabbitevents/rabbitevents/actions/workflows/testing.yml/badge.svg?branch=10.x)](https://github.com/rabbitevents/rabbitevents/actions/workflows/testing.yml?query=branch%3A10.x)
[![Coverage Status](https://raw.githubusercontent.com/rabbitevents/rabbitevents/10.x/.github/badges/coverage.svg)](https://github.com/rabbitevents/rabbitevents/actions/workflows/testing.yml)
[![Total Downloads at Nuwber](https://img.shields.io/packagist/dt/nuwber/rabbitevents)](https://packagist.org/packages/nuwber/rabbitevents)
[![Total Downloads](https://img.shields.io/packagist/dt/rabbitevents/rabbitevents)](https://packagist.org/packages/rabbitevents/rabbitevents)
[![Latest Version](https://img.shields.io/packagist/v/rabbitevents/rabbitevents)](https://packagist.org/packages/rabbitevents/rabbitevents)
[![License](https://img.shields.io/packagist/l/rabbitevents/rabbitevents)](https://packagist.org/packages/rabbitevents/rabbitevents)

> [!IMPORTANT]
> **Repository & Package Migration Announcement**
>
> RabbitEvents has moved to its own dedicated organization!
> - **GitHub Repository**: [github.com/rabbitevents/rabbitevents](https://github.com/rabbitevents/rabbitevents) (formerly `nuwber/rabbitevents`)
> - **Composer Package**: `rabbitevents/rabbitevents` (starting from version 10.0)
>
> Please update your `composer.json` to require `rabbitevents/rabbitevents:^10.0`. All PHP classes and namespaces (`RabbitEvents\...`) remain identical, so no application code changes are required.

Let's imagine a use case: a User made a payment. You need to handle this payment, register the user, send him emails, send analytics data to your analysis system, and so on. The modern infrastructure requires you to create microservices that do their specific job and only it: one handles payments, one is for user management, one is the mailing system, one is for analysis. How to let all of them know that a payment succeeded and handle this message? The answer is "To use RabbitEvents".

RabbitEvents helps you publish an event and handle it seamlessly in other applications across your microservice ecosystem.

## Demo
![rabbit-events-demo](https://github.com/nuwber/rabbitevents/assets/142213350/89df6194-e49d-4d58-8286-8932f182da4b)

## Table of Contents
1. [Installation via Composer](#installation)
   * [Configuration](#configuration)
1. [Upgrade from 9.x to 10.x](#upgrade_9.x-10.x)
1. [Quick Start](#quick-start)
   * [Publishing Events](#quick-start-publisher)
   * [Listening for Events](#quick-start-listener)
1. [Reliability & Guarantees (Zero-DB Architecture)](#reliability)
   * [Message IDs & Observability](#message-ids)
   * [Retries](#retries)
   * [Failed Messages](#failed-messages)
   * [Kill Switch](#kill-switch)
   * [Deduplication](#deduplication)
   * [Transactional Publishing (ShouldPublishAfterCommit)](#transactional-publishing)
   * [Distributed Tracing & Context Propagation](#distributed-tracing)
1. [Listeners & Payloads](#listeners-payloads)
1. [Custom Serializers](#custom-serializers)
1. [Extensions & Ecosystem](#extensions)
1. [Examples](./examples)
1. [Speeding up RabbitEvents](#speeding-up-rabbitevents)
1. [Testing](#testing)
1. [Non-standard use](#non-standard-use)
1. [License](#license)

## Installation via Composer<a name="installation"></a>
You can use Composer to install RabbitEvents into your Laravel project:

```bash
composer require rabbitevents/rabbitevents
```

### Configuration<a name="configuration"></a>
After installing RabbitEvents, publish its configuration file and service provider using the `rabbitevents:install` command:

```bash
php artisan rabbitevents:install
```

This installs `config/rabbitevents.php` and `app/Providers/RabbitEventsServiceProvider.php`.

```php
<?php

use Enqueue\AmqpTools\RabbitMqDlxDelayStrategy;

return [
    'default' => env('RABBITEVENTS_CONNECTION', 'rabbitmq'),

    'default_serializer' => \RabbitEvents\Foundation\Serialization\JsonSerializer::class,

    'connections' => [
        'rabbitmq' => [
            'driver' => 'rabbitmq',
            'exchange' => env('RABBITEVENTS_EXCHANGE', 'events'),
            'durable' => env('RABBITEVENTS_QUEUE_DURABLE', true),
            'host' => env('RABBITEVENTS_HOST', 'localhost'),
            'port' => env('RABBITEVENTS_PORT', 5672),
            'user' => env('RABBITEVENTS_USER', 'guest'),
            'pass' => env('RABBITEVENTS_PASSWORD', 'guest'),
            'vhost' => env('RABBITEVENTS_VHOST', 'events'),
            'delay_strategy' => env('RABBITEVENTS_DELAY_STRATEGY', RabbitMqDlxDelayStrategy::class),
            'ssl' => [
                'is_enabled' => env('RABBITEVENTS_SSL_ENABLED', false),
                'verify_peer' => env('RABBITEVENTS_SSL_VERIFY_PEER', true),
                'cafile' => env('RABBITEVENTS_SSL_CAFILE'),
                'local_cert' => env('RABBITEVENTS_SSL_LOCAL_CERT'),
                'local_key' => env('RABBITEVENTS_SSL_LOCAL_KEY'),
                'passphrase' => env('RABBITEVENTS_SSL_PASSPHRASE', ''),
            ],
            'read_timeout' => env('RABBITEVENTS_READ_TIMEOUT', 3.0),
            'write_timeout' => env('RABBITEVENTS_WRITE_TIMEOUT', 3.0),
            'connection_timeout' => env('RABBITEVENTS_CONNECTION_TIMEOUT', 3.0),
            'heartbeat' => env('RABBITEVENTS_HEARTBEAT', 0),
            'persisted' => env('RABBITEVENTS_PERSISTED', false),
            'lazy' => env('RABBITEVENTS_LAZY', true),
            'qos' => [
                'global' => env('RABBITEVENTS_QOS_GLOBAL', false),
                'prefetch_size' => env('RABBITEVENTS_QOS_PREFETCH_SIZE', 0),
                'prefetch_count' => env('RABBITEVENTS_QOS_PREFETCH_COUNT', 1),
            ],
        ],
    ],

    // Move messages that failed all tries to `{queue}.failed` instead of dropping them.
    'failed_queue' => [
        'enabled' => env('RABBITEVENTS_FAILED_QUEUE_ENABLED', true),
    ],


    'logging' => [
        'enabled' => env('RABBITEVENTS_LOG_ENABLED', false),
        'level' => env('RABBITEVENTS_LOG_LEVEL', 'info'),
        'channel' => env('RABBITEVENTS_LOG_CHANNEL'),
    ],
];
```

## Upgrade from 9.x to 10.x<a name="upgrade_9.x-10.x"></a>

### Compatibility With 9.x

10.x and 9.x services can run side by side and be upgraded one by one, with no queue migration:

* Exchanges, queues and delay queues are declared exactly as in 9.x, so 9.x and 10.x workers can even consume the same queue.
* Messages keep the 9.x format. 10.x only adds the AMQP `message_id` and the `trace_context` property, which 9.x ignores.
* A message released for retry by one version is handled by the other, with `x-attempts` preserved.
* A 9.x message that fails in a 10.x worker is moved to `{queue}.failed` and gets a `message_id` there, so it can be retried or deleted by ID.

### Breaking Changes & Requirements

#### 1. Composer Package Name Changed
RabbitEvents is now an independent project maintained under `rabbitevents/rabbitevents`.
To upgrade, replace `nuwber/rabbitevents` in your `composer.json` or run:

```bash
composer remove nuwber/rabbitevents
composer require rabbitevents/rabbitevents:^10.0
```

> **Zero Namespace Changes:** The PHP namespace remains `RabbitEvents\...`. You do **not** need to change any `use` statements or event classes in your codebase.

#### 2. PHP 8.2+ and Laravel 11+ Required
RabbitEvents 10.x requires PHP 8.2 or greater and Laravel 11.0 or greater. Distributed tracing uses Laravel's native `Context` facade.

#### 3. Return Value of `publish()`
`publish()` and `Event::publish()` now return the message ID (UUIDv4) instead of `void`. It is `null` for an `afterCommit` event published inside a transaction, because the message is sent only on commit.

```php
$messageId = publish('user.registered', $payload);
```

#### 4. Failed Messages Are Kept
In 9.x a message that failed all its tries was dropped. 10.x moves it to `{queue}.failed`. Set `RABBITEVENTS_FAILED_QUEUE_ENABLED=false` to keep the 9.x behaviour.

#### 5. Contracts for Custom Transports
* `Contracts\Connection` requires `makeFailedQueue(string $queueName): FailedDestination`, declaring the failed queue of `$queueName`.
* `Contracts\QueueConsumer` requires `requeue(array $messages)`, which returns messages to the queue keeping their order.
* `Contracts\TransportMessage` requires `setRoutingKey()`, `getMessageId()` and `setMessageId()`.
* A producer that can delay a delivery implements `Contracts\DelaysDelivery`; listener retries of other producers are sent right away.

---

### What's New in 10.0

* **Failed message management:** Messages that failed all tries are kept in `{queue}.failed` and managed with `rabbitevents:failed`, `retry`, `forget` and `flush`.
* **Message IDs:** Every published message has a UUID, returned by `publish()` and shown in listener logs.
* **Transactional publishing:** Publish events only after the database transaction commits, via `ShouldPublishAfterCommit` or `->afterCommit()`.
* **Distributed tracing:** Laravel `Context` is propagated from publishers to listeners.
* **Deduplication hook:** `Listener\Contracts\Deduplicator`, implemented by the optional [`rabbitevents/deduplication`](https://github.com/rabbitevents/deduplication) package.
* **Onion-style listener middleware** with `$next`, and new testing assertions (`assertPublishedTimes()`, `assertNothingPublished()`).

---

## Quick Start<a name="quick-start"></a>

### 1. Publishing Events<a name="quick-start-publisher"></a>

#### Method A: Using the `publish()` helper
The quickest way to dispatch an event from anywhere in your application:

```php
$messageId = publish('user.registered', [
    'user_id' => 123,
    'email' => 'alex@example.com',
    'created_at' => now()->toIso8601String(),
]);

logger()->info("Published event user.registered with ID: {$messageId}");
```

#### Method B: Using an Event Class
Define a dedicated event class implementing `ShouldPublish`:

```php
namespace App\Events;

use App\Models\User;
use RabbitEvents\Publisher\ShouldPublish;
use RabbitEvents\Publisher\Support\Publishable;

class UserRegistered implements ShouldPublish
{
    use Publishable;

    public function __construct(public User $user)
    {
    }

    public function publishEventKey(): string
    {
        return 'user.registered';
    }

    public function toPublish(): array
    {
        return [
            'user_id' => $this->user->id,
            'email' => $this->user->email,
        ];
    }
}
```

Dispatch it just like standard Laravel events:

```php
$messageId = UserRegistered::publish($user);
```

---

### 2. Listening for Events<a name="quick-start-listener"></a>

#### Method A: Using PHP Attributes (Recommended)
Define a listener and tag it with `#[Listener]`:

```php
namespace App\Listeners;

use RabbitEvents\Listener\Attributes\Listener;

#[Listener('user.registered')]
class SendWelcomeEmail
{
    public function handle(array $payload): void
    {
        $userId = $payload['user_id'];
        $email = $payload['email'];

        // Send email or trigger internal processing...
    }
}
```

#### Method B: Using `RabbitEventsServiceProvider`
Register listeners explicitly in `app/Providers/RabbitEventsServiceProvider.php`:

```php
protected array $listen = [
    'user.registered' => [
        \App\Listeners\SendWelcomeEmail::class,
    ],
    'order.*' => [
        \App\Listeners\OrderAuditSubscriber::class,
    ],
];
```

#### Start the Worker
Run the listener worker from your terminal or process manager (Supervisor, Docker, Kubernetes):

```bash
php artisan rabbitevents:listen user.registered
```

Or listen to several events, or to all registered events when none are given:

```bash
php artisan rabbitevents:listen user.registered,order.completed
php artisan rabbitevents:listen
```

---

## Reliability & Guarantees (Zero-DB Architecture)<a name="reliability"></a>

RabbitEvents is designed for microservice environments where services should remain isolated and autonomous. It provides enterprise-level reliability **without requiring MySQL tables, Redis, or database migrations**.

### Message IDs & Observability<a name="message-ids"></a>
Every published event automatically receives a unique RFC 4122 UUIDv4 `message_id`.

```php
$messageId = publish('payment.processed', ['amount' => 99.00]);
// $messageId => "3fa85f64-5717-4562-b3fc-2c963f66afa6"
```

Listener worker console logs display the message ID and execution time in real-time:
```text
[3fa85f64-5717-4562-b3fc-2c963f66afa6] [2026-10-20 12:00:00] Processing: payment.processed:App\Listeners\HandlePayment@handle
[3fa85f64-5717-4562-b3fc-2c963f66afa6] [2026-10-20 12:00:00] Processed:  payment.processed:App\Listeners\HandlePayment@handle (14.22ms)
```

### Retries<a name="retries"></a>
A listener that throws is tried again after `--sleep` seconds, up to `--tries` times. A listener class can set its own limits, and each listener of a message counts its own tries:

```php
class SendInvoice
{
    public int $tries = 5;
    public array $backoff = [1, 5, 30]; // seconds after the 1st, 2nd and every further failed try

    public function handle(array $payload): void
    {
        // ...
    }
}
```

See the [Listener documentation](./src/RabbitEvents/Listener/README.md#retries) for details.

### Failed Messages<a name="failed-messages"></a>
When a listener exhausts its tries (`--tries=3` or its own `$tries`), times out, or throws `FailedException`, RabbitEvents moves the message to the `{queue}.failed` queue instead of dropping it.

The failed message keeps its payload, `message_id` and `trace_context`, and gets the properties `x-failed-at`, `x-original-routing-key`, `x-original-queue`, `x-exception-class`, `x-exception-message`, `x-exception-trace` and `x-listener`. Listeners that already succeeded are recorded in `x-succeeded-listeners` and skipped on retry.

```bash
# List failed messages, for all registered events or a given scope
php artisan rabbitevents:failed
php artisan rabbitevents:failed --events="user.registered,order.*"
php artisan rabbitevents:failed --queue=my-custom-queue

# Move a failed message, or all of them, back to the original queue
php artisan rabbitevents:retry 3fa85f64-5717-4562-b3fc-2c963f66afa6
php artisan rabbitevents:retry --all --events="user.registered"

# Delete one failed message, or all of them
php artisan rabbitevents:forget 3fa85f64-5717-4562-b3fc-2c963f66afa6
php artisan rabbitevents:flush
```

See the [Listener documentation](./src/RabbitEvents/Listener/README.md#failed-messages) for details.

### Kill Switch<a name="kill-switch"></a>
When a dependency of the listeners is down and most messages fail, a worker with the kill switch stops consuming for a while instead of moving the whole queue to `{queue}.failed`. The untouched messages stay in the queue. It is disabled by default:

```dotenv
RABBITEVENTS_KILL_SWITCH_ENABLED=true
```

See the [Listener documentation](./src/RabbitEvents/Listener/README.md#kill-switch) for the thresholds and events.

### Deduplication<a name="deduplication"></a>
RabbitMQ delivers a message *at least once*: when the acknowledgement doesn't reach it (lost connection, killed worker), it delivers the message again. RabbitEvents relies on RabbitMQ only and doesn't deduplicate such redeliveries, so listeners should be idempotent. If your service has a cache store shared by its workers, the optional [`rabbitevents/deduplication`](https://github.com/rabbitevents/deduplication) package skips messages redelivered after they were handled. See the [Listener documentation](./src/RabbitEvents/Listener/README.md#deduplication).

### Transactional Publishing (`afterCommit`)<a name="transactional-publishing"></a>
Prevent race conditions by ensuring events are published only after database transactions commit. You can implement `ShouldPublishAfterCommit` or call `->afterCommit()`:

```php
use RabbitEvents\Publisher\ShouldPublish;
use RabbitEvents\Publisher\ShouldPublishAfterCommit;
use RabbitEvents\Publisher\Support\Publishable;

// Option 1: Marker interface
class InvoicePaid implements ShouldPublishAfterCommit
{
    use Publishable;
}

// Option 2: Per publish, via the Publishable trait
publish((new OrderShipped($order))->afterCommit());
```

> The `$afterCommit` property is declared by the `Publishable` trait, so don't redeclare it in your event class: PHP rejects a trait property redeclared with a different default.

### Distributed Tracing & Context Propagation<a name="distributed-tracing"></a>
Laravel `Context` data is sent with each message in the `trace_context` property and added to the listener's `Context` while the message is handled:

```php
use Illuminate\Support\Facades\Context;

// In Service A:
Context::add('trace_id', (string) \Illuminate\Support\Str::uuid());
publish('order.created', $orderData);

// In Service B (Listener):
// Context::get('trace_id') is automatically restored!
```

## Listeners & Payloads<a name="listeners-payloads"></a>

How the payload is passed to your listener depends on the serialization format:

**JSON (Default):**
The payload is decoded as an **associative array**.
```php
public function handle(array $payload) {
    // $payload['key']
}
```

**Protobuf:**
The payload is passed as the **Message Object** itself.
```php
public function handle(\Google\Protobuf\Internal\Message $message) {
    // $message->getSomething()
}
```

## Custom Serializers<a name="custom-serializers"></a>

You can implement your own serializer by implementing `RabbitEvents\Foundation\Contracts\Serializer` and `RabbitEvents\Foundation\Contracts\ContentType`.

```php
use RabbitEvents\Foundation\Contracts\Serializer;
use RabbitEvents\Foundation\Contracts\ContentType;
use RabbitEvents\Foundation\Contracts\Payload;
use RabbitEvents\Foundation\Contracts\TransportMessage;

class MySerializer implements Serializer
{
    public function serialize(mixed $payload): Payload { /* ... */ }
    public function deserialize(TransportMessage $message): Payload { /* ... */ }
    public function contentType(): ContentType { return new MyContentType(); }
    public function canSerialize(mixed $payload): bool { /* ... */ }
}
```

Register your serializer in a ServiceProvider:

```php
$registry = $this->app->make(\RabbitEvents\Foundation\Serialization\SerializerRegistry::class);
$registry->register(new MySerializer());
```

New serializers are prepended to the registry, so they can override default behavior if they claim the payload.

## Extensions & Ecosystem<a name="extensions"></a>

- [Redis Streams Transport](https://github.com/rabbitevents/redis-transport) - Redis Streams transport driver for RabbitEvents.
- [Deduplication](https://github.com/rabbitevents/deduplication) - Skips redelivered messages using a Laravel cache store shared by the workers.
- [Symfony Bundle](https://github.com/rabbitevents/symfony-bundle) - Official Symfony integration bundle for RabbitEvents.
- [Protobuf Support](https://github.com/rabbitevents/protobuf) - Official Google Protobuf serialization support for RabbitEvents. Required for using Protobuf messages.

## Examples<a name="examples"></a>

Practical implementation examples and ready-to-use boilerplate classes are available in the [examples](./examples) directory:

- **Publishers:**
  - [`UserCreated.php`](./examples/UserCreated.php) - Standard publishable event with payload mapping and publishing helper.
  - [`TransactionalEvent.php`](./examples/TransactionalEvent.php) - Transaction-safe event published only after DB commits (`ShouldPublishAfterCommit`).
  - [`ContextTracingEvent.php`](./examples/ContextTracingEvent.php) - Distributed tracing via Laravel `Context` and capturing the returned message ID.
- **Listeners & Subscribers:**
  - [`AttributeListener.php`](./examples/AttributeListener.php) - Clean attribute-based listener with `#[Listener]`.
  - [`OrderEventsSubscriber.php`](./examples/OrderEventsSubscriber.php) - Handling multiple domain events within a single subscriber class.
  - [`IdempotentPaymentListener.php`](./examples/IdempotentPaymentListener.php) - Idempotent processing guarded by a business key, and trace ID extraction.
  - [`WildcardListener.php`](./examples/WildcardListener.php) - Wildcard event pattern matching and conditional propagation.
  - [`Listener.php`](./examples/Listener.php) - Standard listener with a custom middleware stack.
- **Configuration & Providers:**
  - [`RabbitEventsServiceProvider.php`](./examples/RabbitEventsServiceProvider.php) - Explicit listener mapping via the `$listen` array.
  - [`ExampleMiddleware.php`](./examples/ExampleMiddleware.php) - Custom event middleware implementation.

## Speeding up RabbitEvents<a name="speeding-up-rabbitevents"></a>
To enhance the performance of RabbitEvents, consider installing the `php-amqp` extension along with the `enqueue/amqp-ext` package. 
By doing so, RabbitEvents will utilize the `enqueue/amqp-ext` package instead of the default `enqueue/amqp-lib` package. 
This substitution is advantageous because the C-written `php-amqp` package significantly outperforms the PHP-written `enqueue/amqp-lib` package.

You can install the `php-amqp` extension using the following command:
```bash
pecl install amqp
```
or use the way you prefer. More about it can be found [here](https://pecl.php.net/package/amqp).

Next, install the `enqueue/amqp-ext` package with the following command:
```bash
composer require enqueue/amqp-ext
```
No additional configuration is required.

## Testing <a name="testing"></a>

We always write tests. Tests in our applications contain many mocks and fakes to test how events are published.

There is the `PublishableEventTesting` trait that provides assertion methods in an Event class that you want to test.

**Event.php**

```php
<?php

namespace App\RabbitEvents;

use RabbitEvents\Publisher\ShouldPublish;
use RabbitEvents\Publisher\Support\Publishable;
use RabbitEvents\Publisher\Support\PublishableEventTesting;

class Event implements ShouldPublish
{
    use Publishable;
    use PublishableEventTesting;

    public function __construct(private array $payload) 
    {
    }

    public function publishEventKey(): string
    {
        return 'something.happened';
    }

    public function toPublish(): array
    {
        return $this->payload;
    }
}
```

**Test.php**

```php
<?php

use \App\RabbitEvents\Event;
use \App\RabbitEvents\AnotherEvent;

Event::fake();

Event::publish(['key1' => 'value1', 'key2' => 'value2']);

// Assert event was published
Event::assertPublished();

// Assert with closure
Event::assertPublished(function ($event, $payload) {
    return $payload['key1'] === 'value1';
});

// Assert published exact number of times
Event::assertPublishedTimes(1);

// Assert an event was not published
AnotherEvent::assertNotPublished();
```

`assertNothingPublished()` checks that no event was published at all:

```php
Event::fake();

$service->doNothingInteresting();

Event::assertNothingPublished();
```

`fake()` records events instead of sending them, returns a message ID from `publish()` like the real publisher, and keeps the `afterCommit` behaviour: an event published inside a transaction is recorded only when the transaction commits.

Don't forget to call `\Mockery::close()` in `tearDown` or similar methods of your tests.

## Non-standard use <a name="non-standard-use"></a>

If you're using only one part of RabbitEvents, you should know a few things:

1. You remember, we're using RabbitMQ as the transport layer. In the [RabbitMQ Documentation](https://www.rabbitmq.com/tutorials/tutorial-five-python.html), you can find examples of how to publish your messages using a routing key. This routing key is the event name, like `something.happened` from the examples above.

1. RabbitEvents expects that a message body is a JSON-encoded array. Every element of an array will be passed to a Listener as a separate variable. For example:
```json
[
  {
    "key": "value"  
  },
  "string",
  123 
]
```

There are 3 elements in this array, so 3 variables will be passed to a Listener (an array, a string, and an integer).
If an associative array is being passed, the Dispatcher wraps this array by itself.

# License <a name="license"></a>

RabbitEvents is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
