# Changelog

All notable changes to this project will be documented in this file.

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
