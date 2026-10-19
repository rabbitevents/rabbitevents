<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Console;

use Illuminate\Console\Command;

class FailedCommand extends Command
{
    use InteractsWithFailedQueue;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitevents:failed
                            {--events= : The events the queue was listening for}
                            {--queue= : The failed queue name (e.g. service:events.failed)}
                            {--connection= : The connection name}
                            {--service= : The service name}
                            {--limit=50 : The maximum number of failed messages to display}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all failed messages in the Dead Letter Queue';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $rows = $this->getFailedMessages((int) $this->option('limit'));

        if (empty($rows)) {
            $this->info("No failed messages found in [{$this->getFailedQueueName()}].");
            return 0;
        }

        $this->displayFailedMessages($rows);

        return 0;
    }

    protected function getFailedMessages(int $limit): array
    {
        $rows = [];

        $this->eachFailedMessage(function ($message) use (&$rows, $limit) {
            $rows[] = $this->formatRow($message);

            return count($rows) < $limit;
        });

        return $rows;
    }

    protected function formatRow($message): array
    {
        return [
            $message->id() ?? 'N/A',
            $this->extractEvent($message),
            $this->extractFailedAt($message),
            $this->extractException($message),
        ];
    }

    protected function displayFailedMessages(array $rows): void
    {
        $this->table(['ID', 'Event', 'Failed At', 'Exception'], $rows);
    }
}
