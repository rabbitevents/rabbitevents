<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener\Console;

use ArrayAccess;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use RabbitEvents\Foundation\Context;
use RabbitEvents\Foundation\Serialization\JsonSerializer;
use RabbitEvents\Foundation\Serialization\SerializerRegistry;
use RabbitEvents\Tests\Foundation\Stubs\ConnectionStub;
use RabbitEvents\Tests\Foundation\Stubs\ProducerStub;
use RabbitEvents\Tests\Foundation\Stubs\QueueConsumerStub;
use RabbitEvents\Tests\Foundation\Stubs\TransportMessageStub;
use RabbitEvents\Tests\Listener\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class FailedQueueTestCase extends TestCase
{
    protected function createMockApplication(array $config = []): Container
    {
        $app = new Container();
        $configObj = new class(array_merge([
            'app.name' => 'test-service',
            'rabbitevents.default' => 'rabbitmq',
        ], $config)) implements ArrayAccess {
            public function __construct(private array $items) {}
            public function offsetExists($offset): bool { return isset($this->items[$offset]); }
            public function offsetGet($offset): mixed { return $this->items[$offset] ?? null; }
            public function offsetSet($offset, $value): void { $this->items[$offset] = $value; }
            public function offsetUnset($offset): void { unset($this->items[$offset]); }
            public function get(string $key, mixed $default = null): mixed { return $this->items[$key] ?? $default; }
        };

        $app->instance('config', $configObj);

        return $app;
    }

    protected function createContext(array $messages = []): array
    {
        $consumer = new QueueConsumerStub($messages);
        $producer = new ProducerStub();

        $connection = new ConnectionStub();
        $connection->consumer = $consumer;
        $connection->producer = $producer;

        $registry = new SerializerRegistry();
        $registry->register(new JsonSerializer());

        $context = new Context($connection, $registry);

        return [$context, $producer, $consumer];
    }

    protected function createFailedTransportMessage(
        ?string $id,
        string $event = 'order.created',
        ?string $originalQueue = null,
        ?string $failedAt = null,
        string $exception = 'Something failed'
    ): \RabbitEvents\Foundation\Contracts\TransportMessage {
        $properties = $id !== null ? ['message_id' => $id] : [];
        $message = new \RabbitEvents\Foundation\Message(
            $event,
            new \RabbitEvents\Foundation\Support\JsonPayload(['data' => 'test']),
            $properties
        );
        $message->increaseAttempts();
        // As Processor counts the attempt of the listener that failed
        (new \RabbitEvents\Listener\Message\Handler(
            $message,
            static fn() => null,
            'App\\Listeners\\OrderListener@handle',
            \Mockery::mock(\RabbitEvents\Listener\Support\Releaser::class)
        ))->countAttempt();
        $message->markAsFailed(new \RuntimeException($exception), 'App\\Listeners\\OrderListener');

        $dlxProducer = new ProducerStub();
        $failer = new \RabbitEvents\Listener\Support\Failer(
            new \RabbitEvents\Tests\Foundation\Stubs\FailedDestinationStub($originalQueue ?? 'test-service:'),
            $dlxProducer
        );
        $failer->send($message);

        $transportMessage = $dlxProducer->sent[0]['message'];

        if ($failedAt !== null) {
            $transportMessage->setProperty('x-failed-at', $failedAt);
        }

        return $transportMessage;
    }

    /**
     * @return array{int, string}
     */
    protected function runCommand(Command $command, Container $app, array $parameters = []): array
    {
        $command->setLaravel($app);

        $input = new ArrayInput($parameters);
        $bufferedOutput = new BufferedOutput();
        $output = new OutputStyle($input, $bufferedOutput);

        $input->bind($command->getDefinition());
        $command->setInput($input);
        $command->setOutput($output);

        return [$command->handle(), $bufferedOutput->fetch()];
    }
}
