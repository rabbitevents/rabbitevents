<?php

namespace RabbitEvents\Tests\Foundation\Serialization;

use Interop\Amqp\Impl\AmqpMessage;
use RabbitEvents\Foundation\Contracts\Payload;
use RabbitEvents\Foundation\Serialization\JsonSerializer;
use RabbitEvents\Foundation\Amqp\AmqpTransportMessage;
use RabbitEvents\Tests\Foundation\TestCase;

class JsonSerializerTest extends TestCase
{
    public function testSerialize(): void
    {
        $serializer = new JsonSerializer();
        $payload = $serializer->serialize(['foo' => 'bar']);

        self::assertInstanceOf(Payload::class, $payload);
        self::assertSame(json_encode(['foo' => 'bar']), $payload->serialize());
    }



    public function testDeserialize(): void
    {
        $serializer = new JsonSerializer();
        
        $message = new AmqpMessage();
        $message->setBody(json_encode(['foo' => 'bar']));
        
        $payload = $serializer->deserialize(new AmqpTransportMessage($message));

        self::assertInstanceOf(Payload::class, $payload);
        self::assertSame(['foo' => 'bar'], $payload->value());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('payloadsAcceptedByCanSerialize')]
    public function testSerializesEveryPayloadItAccepts(mixed $payload, string $json): void
    {
        $serializer = new JsonSerializer();

        self::assertTrue($serializer->canSerialize($payload));
        self::assertSame($json, $serializer->serialize($payload)->serialize());
    }

    public static function payloadsAcceptedByCanSerialize(): array
    {
        return [
            'array' => [['a' => 1], '{"a":1}'],
            'string' => ['hello', '"hello"'],
            'int' => [42, '42'],
            'bool' => [true, 'true'],
            'stdClass' => [(object) ['a' => 1], '{"a":1}'],
        ];
    }

    public function testDeserializesJsonScalarsAndNull(): void
    {
        $serializer = new JsonSerializer();

        foreach (['"hello"' => 'hello', '42' => 42, 'null' => null] as $body => $value) {
            $message = new AmqpMessage();
            $message->setBody((string) $body);

            self::assertSame($value, $serializer->deserialize(new AmqpTransportMessage($message))->value());
        }
    }
}
