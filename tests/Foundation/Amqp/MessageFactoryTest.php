<?php

namespace RabbitEvents\Tests\Foundation\Amqp;

use Interop\Amqp\Impl\AmqpMessage;
use RabbitEvents\Foundation\Amqp\AmqpMessageFactory;
use RabbitEvents\Foundation\Contracts\Payload;
use RabbitEvents\Foundation\Contracts\TransportMessage;
use RabbitEvents\Tests\Foundation\TestCase;

class MessageFactoryTest extends TestCase
{
    public function testMake()
    {
        $payload = new class implements Payload {

            public function serialize(): string
            {
                return json_encode($this->value());
            }

            public function value(): mixed
            {
                return ['some' => 'payload'];
            }

            public function contentType(): \RabbitEvents\Foundation\Contracts\ContentType
            {
                return new \RabbitEvents\Foundation\Serialization\JsonContentType();
            }
        };

        $factory = new AmqpMessageFactory();
        $result = $factory->make('event', $payload, ['x-test' => 'property']);

        self::assertInstanceOf(TransportMessage::class, $result);
        self::assertEquals($payload->serialize(), $result->getBody());
        self::assertEquals('property', $result->getProperty('x-test'));
        
        $origin = $result->getOrigin();
        self::assertInstanceOf(AmqpMessage::class, $origin);
        self::assertEquals('event', $origin->getRoutingKey());
        self::assertEquals('UTF-8', $origin->getProperty('content_encoding'));
        self::assertEquals('application/json', $origin->getProperty('content_type'));
    }

    public function testMessageIdIsAnAmqpPropertyNotAnApplicationHeader(): void
    {
        $payload = new \RabbitEvents\Foundation\Support\JsonPayload(['some' => 'payload']);

        $result = (new AmqpMessageFactory())->make('event', $payload, [
            'message_id' => 'msg-1',
            'trace_context' => ['trace_id' => 't-1'],
        ]);

        self::assertSame('msg-1', $result->getMessageId());
        self::assertSame(['trace_id' => 't-1'], $result->getProperty('trace_context'));
        self::assertArrayNotHasKey('message_id', $result->getProperties());
    }
}
