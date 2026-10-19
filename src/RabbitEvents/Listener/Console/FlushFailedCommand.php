<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Console;

use Illuminate\Console\Command;

class FlushFailedCommand extends Command
{
    use InteractsWithFailedQueue;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitevents:flush
                            {--events= : The events the queue was listening for}
                            {--queue= : The failed queue name (e.g. service:events.failed)}
                            {--connection= : The connection name}
                            {--service= : The service name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush all failed messages from the Dead Letter Queue';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $queueName = $this->getFailedQueueName();
        $count = $this->flushQueue();

        $this->info("All [{$count}] failed messages deleted from [{$queueName}].");

        return 0;
    }

    protected function flushQueue(): int
    {
        $count = 0;

        $this->eachFailedMessage(function ($message, $consumer) use (&$count) {
            $consumer->acknowledge($message);
            $count++;

            return true;
        }, maxScan: PHP_INT_MAX);

        return $count;
    }
}
