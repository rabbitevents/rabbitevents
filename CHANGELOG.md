# Changelog

All notable changes to this project will be documented in this file.

## [10.1.0]

This release is about reliability: workers pause instead of failing a whole queue while a dependency is down, each listener gets its own retry policy, no message is lost or handled in a loop when the worker can't publish, and publishing can go through an outbox.

### Added
- **Outbox hook**: `RabbitEvents\Publisher\Contracts\Outbox` can take published messages to deliver them itself, e.g. from a table written in the transaction of the event. It is bound to `NullOutbox`, which takes none, so nothing changes without an outbox package. The optional [`rabbitevents/outbox`](https://github.com/rabbitevents/outbox) package implements it with a database table (suggested in `composer.json`). An `afterCommit` event reaches the outbox after the commit, as it is published now.
- **`MessagePublished` event**: the publisher dispatches `RabbitEvents\Publisher\Events\MessagePublished` when it sends a message (an outbox, when it sends a message it took).
- **Kill switch**: a worker stops consuming for `restart_timeout` seconds when at least `trip_threshold` percent of the messages it handled in the last `tracking_period` seconds failed (after `activation_threshold` messages), instead of moving the whole queue to `{queue}.failed` while a dependency is down. After the pause, a failing first message trips it again. Disabled by default (`rabbitevents.kill_switch.enabled`, `RABBITEVENTS_KILL_SWITCH_*`); the worker dispatches `KillSwitchTripped` and `KillSwitchRestarted`. Nothing changes on the wire.
- **Retry policy per listener**: a listener class can set `$tries`, `tries()` or `#[Tries]`, and `$backoff`, `backoff()` or `#[Backoff]`. They override `--tries` and `--sleep` for this listener, as in Laravel queued listeners. Both `RabbitEvents\Listener\Attributes` and Laravel's `Illuminate\Queue\Attributes` are supported. Backoff is an int, or an array of delays per attempt whose last value repeats. The options are read from the class without calling its constructor, and `tries()` / `backoff()` get the handler's arguments.

### Changed behaviour
- Tries are counted per listener in the new `x-listener-attempts` property (`[[Class@method, attempts], ...]`; 9.x ignores it). Before, all listeners of a message shared its delivery count, so a listener got fewer tries when another one had failed on the same message. Closures still count deliveries (`x-attempts`). `Handler::attempts()` (also in listener events and logs) returns the attempts of the listener.
- The worker no longer fails a message delivered more than `--tries` times before running its listeners: each listener checks its own tries and is failed without running if it used them up. A message interrupted outside its listeners is moved to `{queue}.failed` on its `--tries`th delivery instead of being released once more.
- `rabbitevents:retry` resets `x-listener-attempts` together with `x-attempts`.
- `failed()` is called on the listener instance that handled the message, so it sees the state `handle()` left, instead of on a new instance.
- `Dispatcher::makeListener()` returns a callable `RegisteredListener` for class and object listeners instead of a `Closure` (closures are still returned as they are); `Handler` accepts any callable. `RegisteredListener` builds the listener and reads its retry policy, `failed()` and middleware; the protected `Dispatcher` methods `instantiateListener()`, `makeListenerInstance()` and `extractMiddleware()` are removed.

### Fixed
- A message failed while its `{queue}.failed` queue was deleted (e.g. by hand while the worker ran) was lost without an error: RabbitMQ dropped the copy as unroutable, and the original was acknowledged. The failed queue is now declared again before each message sent to it.
- A worker whose publishing failed while it could still consume looped forever on one message. When releasing a message failed, the worker returned the original to the queue as it was received, without its attempts, and RabbitMQ gave it back at once: `--tries` never stopped it, and the listeners that had handled it ran again on every round. A message that couldn't be moved to `{queue}.failed` made the worker exit with an exception. Now the worker returns such a message to the queue and stops with exit code 1, and the process manager restarts it. (When RabbitMQ refuses a publish, it closes the channel, and the worker exits with that exception as before.)
- `failed()` of a `[$object, 'method']` or invokable object listener was called on a new instance of its class built by the container, which failed for a constructor with parameters the container can't resolve; the object itself is used now.
- A negative delay before a retry (`--sleep=-1`, or a negative `$backoff`) made RabbitMQ refuse the delay queue (negative `x-message-ttl`, 406) and the worker exit with an exception. The message is now retried right away.

### Deprecated
- The `$container` parameter of `HandlerFactory::__construct()`: it isn't used, since listeners are built by `RegisteredListener`. It will be removed in 11.0.
- The `$failedCallback` parameter of `Handler::__construct()`: `HandlerFactory` doesn't pass it any more, since `failed()` is called through `RegisteredListener`. It will be removed in 11.0.

## [10.0.1]

### Fixed
- `JsonSerializer` accepted scalars and `\stdClass` but `JsonPayload` rejected them with a `TypeError`, both when publishing and when receiving such JSON; `null` is accepted too.
- A listener of an event without data (`publish('user.registered')`, an empty array, JSON `null`) was called without arguments, so `handle(array $payload)` failed with `ArgumentCountError`; it now gets the empty payload.
- Methods of one subscriber listening to the same event were tracked as one listener in `x-succeeded-listeners`: after the first method succeeded, the second was skipped, even on the first delivery. They are now tracked as `Class@method`, which also names the listener in logs and in `x-listener`.
- The listener instance for `failed()` was built for every message; it is now built only when a message fails.
- Listener discovery failed in applications without an `app/Listeners` directory (the Laravel 11+ default).
- A closure or `[$object, 'method']` listener definition (e.g. returned by an overridden `listens()`) made the service provider fail.
- A listener class was registered in the container as an instance for the lifetime of the worker, so its state leaked from one message to the next. Now every message gets a new instance, shared by the listener's middleware and its handler.
- Onion-style middleware (with `$next`) got the payload wrapped into an extra array, and filter middleware of wildcard listeners got an associative payload spread into separate arguments. Every middleware now gets the payload the way a regular listener gets it (after the event name for a wildcard listener); a wildcard listener still gets `($event, $payload)`.
- `assertPublished('mail')` and other event names that are PHP function names called that function. `assertNotPublished()` accepts the same arguments as `assertPublished()`.
- Enums and objects that `json_encode()` can't encode in Laravel `Context` broke publishing.
- `QueueArgumentsMismatchException` also explains a 406 reported by ext-amqp (`enqueue/amqp-ext`).
- The failed-queue commands return browsed messages to the queue even when they fail midway; `--events` values are trimmed.
- A worker that timed out was not stopped if failing the message threw.
- `rabbitevents:listen` failed without `app.name`, and aborted for an event that a wildcard listener receives across several words (`order.*` for `order.item.created`).
- `rabbitevents:cache` ignored `shouldDiscoverEvents()`, and listeners from `$listenerClasses` were lost when loading the cache.
- `rabbitevents:make:observer` without `--model` generated invalid PHP; the model is now guessed from the name (`UserObserver` → `User`).
- `rabbitevents:retry` keeps `x-original-routing-key` no longer, and `retry --all` declares each original queue once instead of once per message.
- `HandlerFactory` accepts any callable, as declared, and doesn't try to build function names as classes.
- Container bindings moved to `register()`, and the config is merged with the package defaults, so keys added in 10.x get their default values in configs published by older versions.
- `rabbitevents/publisher` requires `illuminate/log` (its `Context` facade); `rabbitevents/foundation` doesn't.
- Examples and README snippets that didn't work, including the closure listener example: closures get `($event, $payload)`, as in every released version.

### Changed behaviour
- Listener instances are no longer shared between messages. Bind a listener as a singleton yourself if it must keep state.
- Filter middleware of a wildcard listener receive an associative payload as one argument, as a regular listener does.

## [10.0.0]

10.x interoperates with 9.x on the wire: services can be upgraded one by one, and 9.x and 10.x workers can share queues. No queue migration is needed.

### Added
- **Failed messages**: a message that failed all tries, timed out or threw `FailedException` is moved to `{queue}.failed` instead of being dropped (`rabbitevents.failed_queue.enabled`, default `true`). It gets the properties `x-failed-at`, `x-original-routing-key`, `x-original-queue`, `x-exception-class`, `x-exception-message`, `x-exception-trace` (cut to 4 KB) and `x-listener`. A message without ID (published by 9.x) gets one.
- **Failed message commands**: `rabbitevents:failed`, `rabbitevents:retry {id|--all}`, `rabbitevents:forget {id}` and `rabbitevents:flush`, with `--events`, `--queue`, `--service` and `--connection` options.
- **Succeeded listeners tracking**: listeners that already succeeded on a message are recorded in `x-succeeded-listeners` and skipped on retry.
- **Interrupted messages are not lost**: if processing throws outside the listeners, the worker no longer acknowledges the message. It releases it like a failed listener does (`Releaser::release()`, keeping `x-succeeded-listeners`), or returns the original to the queue if releasing fails. In 9.x such a message was acknowledged and lost.
- **Message IDs**: every published message gets a UUID `message_id`. `Message::id()` returns it; listener logs show it together with the handling duration (`ListenerHandled::$duration`).
- **Transactional publishing**: `ShouldPublishAfterCommit` interface and `Publishable::afterCommit()` publish the event only after the database transaction commits.
- **Context propagation**: Laravel `Context::all()` is sent in the `trace_context` property (objects converted to AMQP-safe values) and added to the listener's `Context` while the message is handled.
- **Deduplication hook**: `Listener\Contracts\Deduplicator` (`isDuplicate()` / `markHandled()` per queue), bound to `NullDeduplicator`. The optional `rabbitevents/deduplication` package implements it with a shared Laravel cache store; the core needs no storage besides RabbitMQ.
- **Onion-style listener middleware** receiving `$next`.
- **Testing**: `assertPublishedTimes()`, `assertNothingPublished()`, and closure matchers in `assertPublished()` / `assertNotPublished()`. `fake()` returns a message ID and respects `afterCommit` (events of rolled back transactions are not recorded).
- `QueueArgumentsMismatchException` with remediation steps when RabbitMQ refuses to redeclare a queue (406 `PRECONDITION_FAILED`).
- Examples in `examples/`.

### Changed
- **Package name**: `rabbitevents/rabbitevents` (it `replace`s `nuwber/rabbitevents`). PHP namespaces are unchanged.
- **Requirements**: PHP 8.2+, Laravel 11, 12 or 13. `ext-bcmath` is no longer required.
- `rabbitevents:listen` declares a `{queue}.failed` queue next to every listener queue, and failed messages accumulate there until retried, forgotten or flushed: monitor its size.
- `publish()` and `Publishable::publish()` return the message ID (`?string`) instead of `void`. It is `null` for an `afterCommit` event published inside a transaction.
- Published messages are persistent (`delivery_mode = 2`).
- `Contracts\Connection` requires `makeFailedQueue(string $queueName): FailedDestination`. `Contracts\FailedDestination` marks a failed queue by type and knows its original queue; `Failer` accepts only a `FailedDestination`.
- `Contracts\QueueConsumer` requires `requeue(array $messages)` (order-preserving return to the queue).
- `Releaser` delays retries only through producers implementing `Contracts\DelaysDelivery` (`AmqpProducerAdapter` does); before, a producer without `setDeliveryDelay()` made every delayed retry fail.
- `Contracts\TransportMessage` requires `setRoutingKey()`, `getMessageId()` and `setMessageId()`.
- `Handler` and `HandlerFactory` take a `Listener\Support\Releaser` instead of any `Contracts\Transport`.
- `JsonPayload` implements `\JsonSerializable`.

### Removed
- Laravel 10 support.

## [9.0.0]

### Added
- **Payload Interface Pattern**: `Contracts\Payload` interface with `JsonPayload` implementation.
- **Dynamic Serializer Selection**: `SerializerRegistry` selects serializer based on `content_type` header.
- **Listener Attributes**: Support for `#[Listener]` attribute for auto-registration of events.
- **Configurable Queue Durability**: Added `durable` configuration option to control queue persistence (defaults to `true`).
- **Enums**: `WorkerExitStatus` Enum for worker exit codes.
- **Default Serializer Logic**:
    - `SerializerRegistry` now supports a default serializer.
    - `Consumer` uses the default serializer if the content type header is missing.
- **GitHub Actions**: Added CI workflows for `protobuf` extension.
- **Release Automation**: Added `.github/workflows/releases.yml` and updated `bin/release.sh` to automate multi-repo tagging.

### Changed
- **Architecture**:
    - Moved Protobuf support to a separate package: `rabbitevents/protobuf`.
    - Refactored `Listener` discovery and Console commands.
- **PHP Requirement**: Bumped minimum PHP version to 8.2.
- **Readonly Classes**: `Context`, `Publisher`, and `ListenerOptions` are now `readonly`.
- **Message Class**:
    - Refactored to use `Payload` interface instead of `mixed` payload and `Serializer` dependency.
    - Properties `$event` and `$payload` are now `public readonly`.
    - Removed `event()` and `payload()` accessor methods.
- **Serializer Interface**: `deserialize` method now accepts `array $properties` to support context-aware deserialization.
- **Connection Class Moved**: `RabbitEvents\Foundation\Connection` has been moved to `RabbitEvents\Foundation\Amqp\Connection`.
- **Sender**: Decoupled from `Interop\Amqp\AmqpProducer`, now depends on `Interop\Queue\Producer`.
- **Handler**:
    - Removed `Container` dependency, now accepts `failedCallback` in constructor.
    - Updated constructor signature.
    - `$message` is now a public readonly property.
- **Namespace Refactoring**:
    - Console commands moved to `Console` namespace (e.g., `RabbitEvents\Foundation\Console`).
    - `Releaser` moved to `RabbitEvents\Listener\Support`.
- **InstallCommand**: Updated to support Laravel 11+ `bootstrap/providers.php`.
- **Logging**: `General` logger now sanitizes binary payloads (base64 encoding) to prevent log corruption.
- **JsonSerializer**: Stricter `canSerialize` check to avoid claiming non-JSON payloads.

### Removed
- Legacy internal `Support\Payload` class (replaced by `Contracts\Payload`).
