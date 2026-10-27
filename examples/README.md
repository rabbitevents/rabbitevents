# RabbitEvents Examples

This folder contains practical reference implementations and boilerplate classes for RabbitEvents.

## Publishers
* **[UserCreated.php](./UserCreated.php)** - Standard publishable event with payload mapping and different ways to call `publish()`.
* **[TransactionalEvent.php](./TransactionalEvent.php)** - Safe publishing inside database transactions (`ShouldPublishAfterCommit`).
* **[ContextTracingEvent.php](./ContextTracingEvent.php)** - Distributed tracing with Laravel `Context` and capturing the generated Message ID.

## Listeners & Consumers
* **[AttributeListener.php](./AttributeListener.php)** - Modern PHP attribute listener using `#[Listener('event.name')]`.
* **[OrderEventsSubscriber.php](./OrderEventsSubscriber.php)** - Handling multiple domain events within a single subscriber class.
* **[IdempotentPaymentListener.php](./IdempotentPaymentListener.php)** - Idempotent processing guarded by a business key, with distributed trace ID extraction.
* **[WildcardListener.php](./WildcardListener.php)** - Catch-all and wildcard event handler with matching logic and conditional propagation.
* **[Listener.php](./Listener.php)** - Standard listener with middleware stack attached.
* **[RetryPolicyListener.php](./RetryPolicyListener.php)** - Listener's own retry policy with `#[Tries]` and `#[Backoff]` attributes, overriding `--tries` and `--sleep`.

## Configuration & Providers
* **[RabbitEventsServiceProvider.php](./RabbitEventsServiceProvider.php)** - Example of explicit listener registration via `$listen` array.
* **[ExampleMiddleware.php](./ExampleMiddleware.php)** - Custom middleware intercepting event processing.
