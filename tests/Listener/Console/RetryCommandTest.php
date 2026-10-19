<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener\Console;

use RabbitEvents\Foundation\Context;
use RabbitEvents\Listener\Console\RetryCommand;

class RetryCommandTest extends FailedQueueTestCase
{
    public function test_requires_id_or_all_flag(): void
    {
        $app = $this->createMockApplication();

        [$result, $output] = $this->runCommand(new RetryCommand(), $app);

        self::assertEquals(1, $result);
        self::assertStringContainsString('Please specify the message ID to retry or pass the --all option.', $output);
    }

    public function test_retries_single_message_by_id(): void
    {
        $app = $this->createMockApplication();
        $msg1 = $this->createFailedTransportMessage('msg-first', 'user.created');
        $msgNull = $this->createFailedTransportMessage(null, 'legacy.event');
        $targetMsg = $this->createFailedTransportMessage('msg-123', 'order.created');
        [$context, $producer, $consumer] = $this->createContext([$msg1, $msgNull, $targetMsg]);
        $app->instance(Context::class, $context);

        [$result, $output] = $this->runCommand(new RetryCommand(), $app, ['id' => 'msg-123']);

        self::assertEquals(0, $result);
        self::assertStringContainsString('The failed message [msg-123] has been pushed back onto the queue!', $output);

        self::assertCount(1, $producer->sent);
        self::assertEquals('test-service:', $producer->sent[0]['destination']->getName());
        self::assertEquals(0, $producer->sent[0]['message']->getProperty('x-attempts'));
        self::assertEquals('order.created', $producer->sent[0]['message']->getProperty('event'));
        self::assertEquals('order.created', $producer->sent[0]['message']->getRoutingKey());
        self::assertCount(1, $consumer->acknowledged);
        // The two non-matching messages must be requeued back into the queue
        self::assertCount(2, $consumer->messages);
    }

    public function test_retries_all_messages(): void
    {
        $app = $this->createMockApplication();
        $msg1 = $this->createFailedTransportMessage('msg-1', 'order.created');
        $msg2 = $this->createFailedTransportMessage('msg-2', 'user.registered');
        [$context, $producer, $consumer] = $this->createContext([$msg1, $msg2]);
        $app->instance(Context::class, $context);

        [$result, $outputContent] = $this->runCommand(new RetryCommand(), $app, ['--all' => true]);

        self::assertEquals(0, $result);
        self::assertStringContainsString('The failed message [msg-1] has been pushed back onto the queue!', $outputContent);
        self::assertStringContainsString('The failed message [msg-2] has been pushed back onto the queue!', $outputContent);
        self::assertStringContainsString('Retried 2 failed message(s).', $outputContent);

        self::assertCount(2, $producer->sent);
        self::assertCount(2, $consumer->acknowledged);
        self::assertCount(0, $consumer->messages);
    }

    public function test_retries_message_to_queue_extracted_from_x_death(): void
    {
        $app = $this->createMockApplication();
        $transportMsg = $this->createFailedTransportMessage('msg-999', 'order.created', 'custom-worker-queue');
        [$context, $producer, $consumer] = $this->createContext([$transportMsg]);
        $app->instance(Context::class, $context);

        [$result, $output] = $this->runCommand(new RetryCommand(), $app, ['id' => 'msg-999']);

        self::assertEquals(0, $result);
        self::assertStringContainsString('The failed message [msg-999] has been pushed back onto the queue!', $output);

        self::assertCount(1, $producer->sent);
        self::assertEquals('custom-worker-queue', $producer->sent[0]['destination']->getName());
        self::assertEquals(0, $producer->sent[0]['message']->getProperty('x-attempts'));
        self::assertCount(1, $consumer->acknowledged);
    }

    public function test_reports_error_when_message_id_not_found(): void
    {
        $app = $this->createMockApplication();
        $msg1 = $this->createFailedTransportMessage('msg-1', 'order.created');
        $msgNull = $this->createFailedTransportMessage(null, 'legacy.event');
        [$context, $producer, $consumer] = $this->createContext([$msg1, $msgNull]);
        $app->instance(Context::class, $context);

        [$result, $output] = $this->runCommand(new RetryCommand(), $app, ['id' => 'nonexistent-id']);

        self::assertEquals(1, $result);
        self::assertStringContainsString('Failed message [nonexistent-id] not found.', $output);
        self::assertCount(0, $producer->sent);
        self::assertCount(2, $consumer->rejected);
        self::assertCount(2, $consumer->messages);
    }
}
