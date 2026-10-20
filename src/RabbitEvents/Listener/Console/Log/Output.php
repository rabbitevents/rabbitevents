<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Console\Log;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Carbon;
use RabbitEvents\Listener\Events\ListenerHandled;
use RabbitEvents\Listener\Events\ListenerHandlerExceptionOccurred;
use RabbitEvents\Listener\Message\Handler;
use Symfony\Component\Console\Application as ConsoleApplication;

class Output extends Writer
{
    public function __construct(protected OutputStyle $output)
    {
    }

    /**
     * @inheritdoc
     */
    public function log($event): void
    {
        $status = $this->getStatus($event);
        $duration = $event instanceof ListenerHandled ? $event->duration : null;

        $this->writeStatus($event->handler, $status, $this->getType($status), $duration);

        if ($event instanceof ListenerHandlerExceptionOccurred) {
            (new ConsoleApplication())->renderThrowable($event->exception, $this->output);
        }
    }

    /**
     * Format the status output for the queue worker.
     *
     * @param  Handler $handler
     * @param  string $status
     * @param  string $type
     * @param  float|null $duration
     * @return void
     */
    protected function writeStatus(Handler $handler, string $status, string $type, ?float $duration = null): void
    {
        $id = $handler->message->id();
        $idPrefix = $id ? "[{$id}] " : '';
        $durationSuffix = $duration !== null ? sprintf(' (%.2fms)', $duration) : '';

        $this->output->writeln(sprintf(
            "%s<{$type}>[%s] %s</{$type}> %s%s",
            $idPrefix,
            Carbon::now()->format('Y-m-d H:i:s'),
            str_pad("{$status}:", 11),
            $handler->getName(),
            $durationSuffix
        ));
    }

    protected function getType($status): string
    {
        return match ($status) {
            self::STATUS_PROCESSED => 'info',
            self::STATUS_FAILED => 'error',
            default => 'comment',
        };
    }
}
