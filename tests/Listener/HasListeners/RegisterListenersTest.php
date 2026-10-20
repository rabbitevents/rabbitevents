<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener\HasListeners;

use RabbitEvents\Listener\HasListeners\RegisterListeners;
use RabbitEvents\Tests\Listener\TestCase;

class RegisterListenersTest extends TestCase
{
    public function testDeduplicatesEveryKindOfListenerTheReadmeAllows(): void
    {
        $closure = static fn(array $payload) => null;
        $object = new RegisterListenersTestListener();

        $listeners = (new class {
            use RegisterListeners;

            public function deduplicate(array $listeners): array
            {
                return $this->deduplicateListeners($listeners);
            }
        })->deduplicate([
            'payment.succeeded' => [
                RegisterListenersTestListener::class,
                RegisterListenersTestListener::class . '@handle', // same as the class name
                [RegisterListenersTestListener::class, 'process'],
                $closure,
                $closure,
                [$object, 'handle'],
            ],
        ]);

        self::assertSame([
            RegisterListenersTestListener::class,
            [RegisterListenersTestListener::class, 'process'],
            $closure,
            [$object, 'handle'],
        ], $listeners['payment.succeeded']);
    }
}

class RegisterListenersTestListener
{
    public function handle(): void
    {
    }

    public function process(): void
    {
    }
}
