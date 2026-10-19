# RabbitEvents Publisher

The RabbitEvents Publisher component provides an API for publishing events across the application structure. More information is available in the [RabbitEvents documentation](https://github.com/rabbitevents/rabbitevents).

The RabbitEvents Publisher is the part that informs all other microservices that a payment has succeeded.

## Table of Contents

1. [Installation via Composer](#installation)
2. [Configuration](#configuration)
3. [Publishing](#how-to-publish)
   - [Using an Event class](#using-an-event-class)
   - [Using the `publish` function](#using-the-publish-function)
   - [Transactional Publishing (`afterCommit`)](#transactional-publishing-aftercommit)
   - [Distributed Tracing & Context Propagation](#distributed-tracing--context-propagation)
   - [Publish an Event object with the `publish` function](#publish-an-event-object-with-the-publish-function)
   - [Publishing Protobuf Messages](#publishing-protobuf-messages)
4. [Testing](#testing)

## Installation via Composer<a name="installation"></a>

RabbitEvents Publisher can be installed via the Composer package manager:

```bash
composer require rabbitevents/publisher
```

After installing Publisher, you can execute the `rabbitevents:install` Artisan command, which will install the RabbitEvents configuration file into your application:

```bash
php artisan rabbitevents:install
```

## Configuration <a name="configuration"></a>

Details about the configuration are described in the library [documentation](https://github.com/rabbitevents/rabbitevents#configuration).

## Publishing<a name="how-to-publish"></a>

### Using an Event class
Here is an example event class:

```php
<?php

use App\Payment;
use App\User;
use RabbitEvents\Publisher\ShouldPublish;
use RabbitEvents\Publisher\Support\Publishable;

class PaymentSucceededRabbitEvent implements ShouldPublish
{
    use Publishable;

    public function __construct(private User $user, private Payment $payment)
    {
    }

    public function publishEventKey(): string
    {
        return 'payment.succeeded';
    }

    public function toPublish(): mixed
    {
        return [
            'user' => $this->user->toArray(),
            'payment' => $this->payment->toArray(),
        ];
    }
}
```

The only requirement for event classes is to implement the `\RabbitEvents\Publisher\ShouldPublish` interface.

As an alternative, you could extend `\RabbitEvents\Publisher\Support\AbstractPublishableEvent`. This class was created to simplify the creation of event classes.

To **publish** this event, you just need to call the `publish` method of the event class and pass all the necessary data:

```php
<?php

$payment = new Payment(...);

// ...

PaymentSucceededRabbitEvent::publish($request->user(), $payment);
```

The method `publish` is provided by the trait `Publishable`.

### Using the `publish` function

Sometimes, it is easier to use the helper function `publish` with an event key and payload:

```php
<?php

$messageId = publish(
    'payment.succeeded',
    [
        'user' => $request->user()->toArray(),
        'payment' => $payment->toArray(),
    ]
);
// $messageId is a unique RFC 4122 v4 UUID string (e.g. "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d")
```

The `publish` helper returns the message ID (`?string`), which you can use for logging, distributed tracing, or auditing.

### Transactional Publishing (`afterCommit`)

If an event is published within a database transaction, you usually want to guarantee that the message is sent only after the database transaction commits successfully:

```php
class PaymentSucceededRabbitEvent implements ShouldPublishAfterCommit
{
    use Publishable;

    // ...
}

// Or per publish:
publish((new PaymentSucceededRabbitEvent($payment))->afterCommit());
```

RabbitEvents then delays publishing until the active database transaction commits. If the transaction rolls back, the event is not published. Inside a transaction `publish()` returns `null`, because the message ID is only known once the message is actually sent.

Don't redeclare `$afterCommit` in your event class: the `Publishable` trait already declares it, and PHP rejects a trait property redeclared with a different default.

### Distributed Tracing & Context Propagation

RabbitEvents copies Laravel's `Context` data (`Context::all()`) into the `trace_context` message property on publish, and the listener adds it to its own `Context` while handling the message. Hidden context (`Context::addHidden()`) is not sent. Objects are converted to AMQP-safe values: models to `Class:id`, `JsonSerializable`/`Arrayable` objects to arrays, `Stringable` objects to strings.

```php
use Illuminate\Support\Facades\Context;

Context::add('trace_id', $request->header('X-Trace-Id'));

publish('order.created', $order->toArray());
// In the listener: Context::get('trace_id')
```

### Publish an Event object with the `publish` function

You can also use a combination of the two previous methods:

```php
<?php

$event = new PaymentSucceededRabbitEvent($request->user(), $payment);

$messageId = publish($event);
```

### Publishing Protobuf Messages

You can publish Google Protobuf messages. The system will automatically handle serialization and set the `content_type` to `application/x-protobuf`.
> **Note**: You must install the `rabbitevents/protobuf` package to use this feature.

```php
use App\Messages\AccountCreated; // Generated Protobuf class

$message = new AccountCreated(['id' => 123, 'name' => 'John']);

publish('account.created', $message);
```
The `type` property will be automatically set to `App\Messages\AccountCreated`, allowing the consumer to re-hydrate the object.

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

// Assert event was published (without repeating the event name)
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
