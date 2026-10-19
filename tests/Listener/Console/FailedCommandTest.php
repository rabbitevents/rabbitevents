<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener\Console;

use RabbitEvents\Foundation\Context;
use RabbitEvents\Listener\Console\FailedCommand;

class FailedCommandTest extends FailedQueueTestCase
{
    public function test_displays_message_when_no_failed_events_found(): void
    {
        $app = $this->createMockApplication();
        [$context] = $this->createContext([]);
        $app->instance(Context::class, $context);

        [$result, $output] = $this->runCommand(new FailedCommand(), $app);

        self::assertEquals(0, $result);
        self::assertStringContainsString('No failed messages found in [test-service:.failed].', $output);
    }

    public function test_displays_failed_messages_in_table_and_requeues_them(): void
    {
        $app = $this->createMockApplication();
        $msg1 = $this->createFailedTransportMessage('uuid-1', 'order.created', null, '1700000000', 'Database timeout');
        $msgNull = $this->createFailedTransportMessage(null, 'order.updated', null, '1700000005', 'Legacy 9.x failure');
        $msg2 = $this->createFailedTransportMessage('uuid-2', 'order.shipped', null, '1700000010', 'Gateway error');
        [$context, , $consumer] = $this->createContext([$msg1, $msgNull, $msg2]);
        $app->instance(Context::class, $context);

        [$result, $outputContent] = $this->runCommand(new FailedCommand(), $app, ['--queue' => 'custom-queue']);

        self::assertEquals(0, $result);
        self::assertStringContainsString('uuid-1', $outputContent);
        // A 9.x message without ID gets one when it is moved to the failed queue.
        self::assertStringContainsString((string) $msgNull->getMessageId(), $outputContent);
        self::assertStringContainsString('uuid-2', $outputContent);
        self::assertStringContainsString('order.created', $outputContent);
        self::assertStringContainsString('order.updated', $outputContent);
        self::assertStringContainsString('order.shipped', $outputContent);
        self::assertStringContainsString('Database timeout', $outputContent);
        self::assertStringContainsString('Gateway error', $outputContent);

        self::assertCount(3, $consumer->requeued);
        // Browsing must not reorder the failed queue.
        self::assertSame([$msg1, $msgNull, $msg2], $consumer->messages);
        self::assertCount(3, $consumer->messages);
    }
}
