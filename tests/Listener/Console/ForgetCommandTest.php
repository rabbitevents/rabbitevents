<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener\Console;

use RabbitEvents\Foundation\Context;
use RabbitEvents\Listener\Console\ForgetCommand;

class ForgetCommandTest extends FailedQueueTestCase
{
    public function test_forgets_message_by_id(): void
    {
        $app = $this->createMockApplication();
        $msg1 = $this->createFailedTransportMessage('msg-keep-1');
        $msgNull = $this->createFailedTransportMessage(null);
        $targetMsg = $this->createFailedTransportMessage('msg-to-delete');
        [$context, , $consumer] = $this->createContext([$msg1, $msgNull, $targetMsg]);
        $app->instance(Context::class, $context);

        [$result, $output] = $this->runCommand(new ForgetCommand(), $app, ['id' => 'msg-to-delete']);

        self::assertEquals(0, $result);
        self::assertStringContainsString('Failed message [msg-to-delete] deleted successfully.', $output);
        self::assertCount(1, $consumer->acknowledged);
        self::assertCount(2, $consumer->messages);
    }

    public function test_reports_error_when_message_not_found(): void
    {
        $app = $this->createMockApplication();
        $msgNull = $this->createFailedTransportMessage(null);
        [$context, , $consumer] = $this->createContext([$msgNull]);
        $app->instance(Context::class, $context);

        [$result, $output] = $this->runCommand(new ForgetCommand(), $app, ['id' => 'nonexistent']);

        self::assertEquals(1, $result);
        self::assertStringContainsString('Unable to find failed message with ID [nonexistent]', $output);
        self::assertCount(1, $consumer->messages);
    }
}
