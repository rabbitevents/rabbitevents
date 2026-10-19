<?php

namespace RabbitEvents\Tests\Listener\Support;

use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Support\Releaser;
use RabbitEvents\Tests\Foundation\TestCase;
use RabbitEvents\Tests\Foundation\Stubs\DestinationStub;
use RabbitEvents\Tests\Foundation\Stubs\ProducerStub;
use RabbitEvents\Tests\Foundation\Stubs\TransportMessageStub;
use RabbitEvents\Tests\Listener\Payload;

class ReleaserTest extends TestCase
{
    private $producerStub;
    private $message;
    private $queueStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queueStub = new DestinationStub();
        $this->message = new Message('some.event', new Payload([]));
        $transportMessage = new TransportMessageStub();
        $this->message->setTransportMessage($transportMessage);

        $this->producerStub = new ProducerStub();
    }

    public function testDelaysDeliveryWhenTheProducerSupportsIt(): void
    {
        $releaser = new Releaser($this->queueStub, $this->producerStub);

        $releaser->setDelay(1);
        $releaser->send($this->message);

        self::assertSame(1, $this->producerStub->deliveryDelay);
        self::assertCount(1, $this->producerStub->sent);
    }

    public function testSendsRightAwayWhenTheProducerCannotDelay(): void
    {
        $producer = new class implements \RabbitEvents\Foundation\Contracts\Producer {
            public array $sent = [];
            public function send(\RabbitEvents\Foundation\Contracts\Destination $destination, \RabbitEvents\Foundation\Contracts\TransportMessage $message): void
            {
                $this->sent[] = $message;
            }
        };
        $releaser = new Releaser($this->queueStub, $producer);

        $releaser->setDelay(5);
        $releaser->send($this->message);

        self::assertCount(1, $producer->sent);
    }

    public function testReleaseSendsTheMessageWithItsProgressAndMarksItReleased(): void
    {
        $this->message->setProperty('x-succeeded-listeners', ['App\\Listeners\\First']);
        $releaser = new Releaser($this->queueStub, $this->producerStub);

        $releaser->release($this->message, 3);

        self::assertSame(3, $this->producerStub->deliveryDelay);
        self::assertSame($this->message->transportMessage(), $this->producerStub->sent[0]['message']);
        self::assertSame(['App\\Listeners\\First'], $this->producerStub->sent[0]['message']->getProperty('x-succeeded-listeners'));
        self::assertTrue($this->message->isReleased());
    }
}
