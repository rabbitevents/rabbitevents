<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener\Support;

use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Support\Failer;
use RabbitEvents\Tests\Foundation\Stubs\FailedDestinationStub;
use RabbitEvents\Tests\Foundation\Stubs\ProducerStub;
use RabbitEvents\Tests\Foundation\Stubs\TransportMessageStub;
use RabbitEvents\Tests\Foundation\TestCase;
use RabbitEvents\Tests\Listener\Payload;

class FailerTest extends TestCase
{
    public function testSendAttachesDiagnosticHeadersAndPublishesToDlx(): void
    {
        $destination = new FailedDestinationStub('billing:order.created');
        $producer = new ProducerStub();
        $failer = new Failer($destination, $producer);

        $message = new Message('order.created', new Payload(['order_id' => 10]), ['message_id' => 'msg-fail-1']);
        $transportMessage = $message->transportMessage();

        $exception = new \RuntimeException('Payment gateway error');
        $message->markAsFailed($exception, 'App\\Listeners\\ChargeOrder');

        $failer->send($message);

        self::assertCount(1, $producer->sent);
        self::assertSame($destination, $producer->sent[0]['destination']);
        self::assertEquals('order.created', $transportMessage->getRoutingKey());

        self::assertIsInt($message->getProperty('x-failed-at'));
        self::assertEquals('order.created', $message->getProperty('x-original-routing-key'));
        self::assertEquals(\RuntimeException::class, $message->getProperty('x-exception-class'));
        self::assertEquals('Payment gateway error', $message->getProperty('x-exception-message'));
        self::assertNotEmpty($message->getProperty('x-exception-trace'));
        self::assertEquals('App\\Listeners\\ChargeOrder', $message->getProperty('x-listener'));
        self::assertEquals('billing:order.created', $message->getProperty('x-original-queue'));
    }

    public function testSendTruncatesExceptionDetailsToKeepHeadersWithinFrameSize(): void
    {
        $producer = new ProducerStub();
        $failer = new Failer(new FailedDestinationStub('billing:order.created'), $producer);

        $message = new Message('order.created', new Payload(['order_id' => 10]));
        $message->markAsFailed(new \RuntimeException(str_repeat('m', 100000)));

        $failer->send($message);

        self::assertLessThanOrEqual(Failer::MAX_PROPERTY_LENGTH, strlen($message->getProperty('x-exception-message')));
        self::assertLessThanOrEqual(Failer::MAX_PROPERTY_LENGTH, strlen($message->getProperty('x-exception-trace')));
    }

    public function testSendPreservesOriginalEventWhenRoutingKeyWasMutatedToQueueName(): void
    {
        $destination = new FailedDestinationStub('billing:order.created');
        $producer = new ProducerStub();
        $failer = new Failer($destination, $producer);

        // Simulate message after Releaser or RetryCommand where AMQP routingKey was mutated to the queue name
        $message = new Message('order.created', new Payload(['order_id' => 10]));
        $message->setRoutingKey('billing:order.created');

        $message->markAsFailed(new \RuntimeException('Failed on attempt 2'));

        $failer->send($message);

        self::assertEquals('order.created', $message->getProperty('x-original-routing-key'));
    }

    public function testSendDoesNotThrowWhenFacadeContainerLacksLogBinding(): void
    {
        \Illuminate\Support\Facades\Facade::setFacadeApplication(new \Illuminate\Container\Container());

        try {
            $destination = new FailedDestinationStub('billing:order.created');
            $producer = new ProducerStub();
            $failer = new Failer($destination, $producer);

            $message = new Message('order.created', new Payload(['order_id' => 10]));
            $message->markAsFailed(new \RuntimeException('Failed without log service'));

            $failer->send($message);

            self::assertCount(1, $producer->sent);
        } finally {
            \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
        }
    }

    public function testSendAssignsIdToMessagesPublishedWithoutOne(): void
    {
        $producer = new ProducerStub();
        $failer = new Failer(new FailedDestinationStub('billing:order.created'), $producer);

        // RabbitEvents 9.x publishers don't set message_id.
        $message = new Message('order.created', new Payload(['order_id' => 10]));
        $message->markAsFailed(new \RuntimeException('boom'));

        $failer->send($message);

        self::assertNotEmpty($message->id());
        self::assertSame($message->id(), $producer->sent[0]['message']->getMessageId());
    }
}
