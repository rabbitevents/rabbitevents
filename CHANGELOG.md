# Changelog

All notable changes to this project will be documented in this file.

## [10.0.0]

### Added
- **Dead Letter Exchange (DLX) & Failed Messages Management**:
    - `DeadLetterConfigurator`: automatically creates `rabbitevents.dlx` topic exchange and declares and binds `{$queue}.failed` queue without mutating existing worker queue arguments.
    - `rabbitevents:failed`: Artisan command to inspect and list failed messages from the DLX queue with routing key, failed time, and exception details.
    - `rabbitevents:retry`: Artisan command to re-queue failed messages back into the original working queue and acknowledge them from the failed queue (`{id}` or `--all`).
    - `rabbitevents:forget`: Artisan command to remove a specific failed message from the failed queue.
    - `rabbitevents:flush`: Artisan command to delete all failed messages from the failed queue.
- **Message Deduplication**:
    - `Contracts\Deduplicator` interface for deduplication strategies.
    - Built-in deduplicators: `InMemoryDeduplicator`, `NullDeduplicator`, and `RabbitMqDeduplicationPluginStrategy` (for `rabbitmq-message-deduplication` plugin).
    - Automatic `x-deduplication-header` header injection on published messages.
    - Integrated deduplication verification in `Processor::process()` to prevent duplicate event executions.
- **Context Tracing (Laravel Context)**:
    - Automatic injection of `Illuminate\Support\Facades\Context::all()` into message headers (`trace_context`) on publishing.
    - Automatic restoration of trace context in `Processor::withContext()` during listener handling, with guaranteed `Context::flush()` on finish.
- **Transactional Publishing**:
    - `ShouldPublishAfterCommit` interface and `$afterCommit` property / `afterCommit()` method on `Publishable` trait to delay event publishing until the database transaction successfully commits.
    - Prevents publishing if the transaction is rolled back.
- **Testing Utilities**:
    - `PublishableEventTesting` trait providing `Event::fake()`, `Event::assertPublished()`, `Event::assertPublishedTimes(n)`, `Event::assertNotPublished()`, and `Event::assertNothingPublished()`.
- **Reference Implementations**:
    - Added runnable examples in `examples/` for Context Tracing, Idempotent Listeners, Transactional Events, and Subscribers.

### Changed
- **Package Namespace Migration**: Renamed package to `rabbitevents/rabbitevents` (maintaining backward-compatible `"replace"` for `nuwber/rabbitevents`). PHP namespaces (`RabbitEvents\...`) remain identical.
- **Framework Compatibility**: Added full support for Laravel 11.x, 12.x, and 13.x on PHP 8.2 – 8.4.
- **Publishing Return Value**: `publish()` helper and `Publishable::publish()` now return the generated message UUID (`?string`) instead of `void`.
- **Transport & Message Architecture**:
    - `Contracts\TransportMessage` extended with `getMessageId()`, `setMessageId()`, `getHeader()`, `getHeaders()`, `setHeader()`, `setHeaders()`, and `setRoutingKey()`.
    - `AmqpTransportMessage` unifies AMQP application headers and basic properties.
    - `Message` class decoupled from direct `Interop\Amqp\AmqpMessage` dependency, now wrapping `Contracts\TransportMessage`.
    - Added unified header accessors: `header()`, `headers()`, and `setHeader()`.
    - Added `id()`, `attempts()`, `increaseAttempts()`, and `hasFailed()` methods.
- **JsonPayload**: Now implements `\JsonSerializable` for seamless serialization with `json_encode()`.
- **Queue Declarations & Seamless 9.x Coexistence**:
    - `QueueFactory` and `DeadLetterConfigurator` automatically configure `rabbitevents.dlx` and `{queue}.failed` bindings when DLX is enabled without adding conflicting queue arguments to existing 9.x queues.
    - `Failer`: Worker routes failed messages with diagnostic headers (`x-exception-message`, `x-exception-class`, `x-exception-trace`, `x-failed-at`, `x-original-routing-key`, `x-listener`, `trace_context`) into `rabbitevents.dlx` exchange, delivering to `{queue}.failed` reliably.
- **Failed Queue Commands Overhaul**:
    - `InteractsWithFailedQueue`: Fixed browsing loop to keep messages unacknowledged during iteration and only requeue unhandled messages upon completion, preventing infinite single-message requeue loops on RabbitMQ.
    - Added `--queue` and `--events` options to `rabbitevents:failed`, `rabbitevents:retry`, `rabbitevents:forget`, and `rabbitevents:flush` for precise queue targeting.
- **Context Tracing & Safety**:
    - Sanitizes non-scalar objects in Laravel `Context` before attaching to AMQP headers to avoid AMQPTable serialization exceptions.
    - Preserves worker-level Context data across message processing without clearing out the parent worker context.
- **Performance & Reliability**:
    - Restored `delay_strategy` configuration in `config/rabbitevents.php`.
    - Restored `$message->event()` accessor on `Message` for backward compatibility.
    - Cached reflection checks in `Dispatcher` for listener middleware execution.
    - Captured synchronous message ID when publishing events configured with `afterCommit` outside of active database transactions.
    - Added explicit `illuminate/log` dependency in `composer.json`.
- **Worker & Processor**: Integrated deduplication checks and trace context lifecycle into message processing loop.
- **CI & Quality**: Replaced external Codecov service with native GitHub Actions test and coverage reporting (`$GITHUB_STEP_SUMMARY`).

### Removed
- Obsolete `bin/splitsh-lite` binaries.

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
