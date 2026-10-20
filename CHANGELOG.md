# Changelog

All notable changes to this project will be documented in this file.

> **9.x is the last version of `nuwber/rabbitevents`.** RabbitEvents continues as [`rabbitevents/rabbitevents`](https://github.com/rabbitevents/rabbitevents) 10.0+; see its [changelog](https://github.com/rabbitevents/rabbitevents/blob/10.x/CHANGELOG.md) and the [upgrade guide](https://github.com/rabbitevents/rabbitevents/tree/10.x#upgrade_9.x-10.x).

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
