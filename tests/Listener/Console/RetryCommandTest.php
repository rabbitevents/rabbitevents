<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener\Console;

use RabbitEvents\Foundation\Context;
use RabbitEvents\Listener\Console\RetryCommand;
use RabbitEvents\Listener\Support\Failer;

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
        self::assertNull($producer->sent[0]['message']->getProperty('x-listener-attempts'), 'Listeners get all their tries again');
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

    public function test_retry_all_declares_each_original_queue_once(): void
    {
        $app = $this->createMockApplication();
        [$context, $producer] = $this->createContext([
            $this->createFailedTransportMessage('msg-1'),
            $this->createFailedTransportMessage('msg-2'),
        ]);
        $app->instance(Context::class, $context);

        $this->runCommand(new RetryCommand(), $app, ['--all' => true]);

        self::assertCount(2, $producer->sent);
        $declared = array_filter($context->connection->createdQueues, fn(array $queue) => empty($queue['is_failed']));
        self::assertCount(1, $declared);
    }

    public function test_retries_message_to_original_queue_recorded_by_failer(): void
    {
        $app = $this->createMockApplication();
        $transportMsg = $this->createFailedTransportMessage('msg-999', 'order.created', 'custom-worker-queue');
        // Released messages pass through the delay queue, so the broker leaves its name in x-death.
        $transportMsg->setProperty('x-death', [['queue' => 'enqueue.custom-worker-queue.5000.delayed', 'reason' => 'expired']]);
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
        self::assertCount(2, $consumer->requeued);
        self::assertCount(2, $consumer->messages);
    }

    public function test_strips_failure_headers_before_republishing(): void
    {
        $app = $this->createMockApplication();
        [$context, $producer] = $this->createContext([$this->createFailedTransportMessage('msg-1')]);
        $app->instance(Context::class, $context);

        $this->runCommand(new RetryCommand(), $app, ['id' => 'msg-1']);

        $retried = $producer->sent[0]['message'];
        foreach (Failer::FAILURE_PROPERTIES as $header) {
            self::assertNull($retried->getProperty($header));
        }
    }

    public function test_retry_all_stops_when_a_retried_message_comes_back_to_the_failed_queue(): void
    {
        $app = $this->createMockApplication();
        $msg = $this->createFailedTransportMessage('msg-1');
        // The listener failed the retried message again before the scan finished.
        [$context, $producer, $consumer] = $this->createContext([$msg, $msg]);
        $app->instance(Context::class, $context);

        [$result, $output] = $this->runCommand(new RetryCommand(), $app, ['--all' => true]);

        self::assertEquals(0, $result);
        self::assertStringContainsString('Retried 1 failed message(s).', $output);
        self::assertCount(1, $producer->sent);
        self::assertCount(1, $consumer->messages);
    }

    public function test_retries_message_published_by_9x_by_the_id_assigned_on_failure(): void
    {
        $app = $this->createMockApplication();
        $legacy = $this->createFailedTransportMessage(null, 'legacy.event');
        [$context, $producer] = $this->createContext([$legacy]);
        $app->instance(Context::class, $context);

        [$result] = $this->runCommand(new RetryCommand(), $app, ['id' => $legacy->getMessageId()]);

        self::assertEquals(0, $result);
        self::assertCount(1, $producer->sent);
    }

    public function test_browsed_messages_go_back_to_the_queue_when_the_scan_fails(): void
    {
        $app = $this->createMockApplication();
        $first = $this->createFailedTransportMessage('msg-1');
        $second = $this->createFailedTransportMessage('msg-2');
        [$context, $producer, $consumer] = $this->createContext([$first, $second]);
        $producer->throwException(new \RuntimeException('Broker unavailable'));
        $app->instance(Context::class, $context);

        try {
            $this->runCommand(new RetryCommand(), $app, ['--all' => true]);
            self::fail('The send failure must be reported');
        } catch (\RuntimeException $e) {
            self::assertSame('Broker unavailable', $e->getMessage());
        }

        // msg-1 failed to be retried and msg-2 wasn't reached yet: both stay in the failed queue.
        self::assertSame([$first, $second], $consumer->messages);
        self::assertCount(0, $consumer->acknowledged);
    }
}
