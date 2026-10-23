<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Console;

use Illuminate\Console\Command;
use RabbitEvents\Foundation\Connection\ConnectionManager;
use RabbitEvents\Foundation\Context;
use RabbitEvents\Listener\Contracts\Deduplicator;
use RabbitEvents\Listener\Support\Failer;
use RabbitEvents\Listener\Support\KillSwitch;
use RabbitEvents\Listener\Support\Releaser;
use RabbitEvents\Listener\Dispatcher;
use RabbitEvents\Listener\Events\ListenerHandled;
use RabbitEvents\Listener\Events\ListenerHandleFailed;
use RabbitEvents\Listener\Events\ListenerHandlerExceptionOccurred;
use RabbitEvents\Listener\Events\KillSwitchRestarted;
use RabbitEvents\Listener\Events\KillSwitchTripped;
use RabbitEvents\Listener\Events\ListenerHandling;
use RabbitEvents\Listener\Events\MessageProcessingFailed;
use RabbitEvents\Listener\Events\WorkerStopping;
use RabbitEvents\Listener\ListenerOptions;
use RabbitEvents\Listener\Message\HandlerFactory;
use RabbitEvents\Listener\Message\Processor;
use RabbitEvents\Listener\Worker;
use RabbitEvents\Listener\WorkerExitStatus;

/**
 * @codeCoverageIgnore
 */
class ListenCommand extends Command
{
    use ResolvesListenerQueue;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitevents:listen
                            {events? : The name of the events to listen to}
                            {--service= : The name of current service. Necessary to identify listeners}
                            {--connection= : The name of the connection to listen on}
                            {--queue= : The queue to listen on}
                            {--memory=128 : The memory limit in megabytes}
                            {--timeout=60 : The number of seconds a message could be handled}
                            {--tries=1 : Number of times to attempt to handle a Message before logging it failed}
                            {--sleep=5 : Sleep time in seconds before handling failed message next time}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listen for events thrown from other services';

    protected array $logWriters = [];

    /**
     * Execute the console command.
     * @param Context $context
     * @param Worker $worker
     * @return int
     */
    public function handle(Context $context, Worker $worker): int
    {
        $this->checkExtLoaded();

        $this->registerLogWriters();
        $this->listenForApplicationEvents();

        $options = $this->gatherOptions();

        if (empty($options->events)) {
            $this->error('No RabbitEvents listeners registered in the application. Process aborted.');

            return WorkerExitStatus::ERROR->value;
        }

        $registeredEvents = $this->laravel[Dispatcher::class]->getEvents();
        $missingEvents = [];

        foreach ($options->events as $event) {
            if (!$this->hasListenerForEvent($event, $registeredEvents)) {
                $missingEvents[] = $event;
            }
        }

        if (!empty($missingEvents)) {
            $this->warn(sprintf(
                'No listeners registered for event(s): %s.',
                implode(', ', $missingEvents)
            ));

            if (count($missingEvents) === count($options->events)) {
                $this->error('None of the specified events have registered listeners. Process aborted.');
                if (!empty($registeredEvents)) {
                    $this->line('Available registered events: ' . implode(', ', $registeredEvents));
                }

                return WorkerExitStatus::ERROR->value;
            }
        }

        if ($this->laravel->bound(ConnectionManager::class)) {
            $context = $this->laravel[ConnectionManager::class]->context($options->connectionName);
        }

        $queueName = $options->queue();

        $queue = $context->makeQueue(
            $queueName,
            $options->events,
            $context->makeTopic()
        );

        $releaser = new Releaser($queue, $context->createProducer());
        $handlerFactory = new HandlerFactory($this->laravel, $releaser);
        $worker->setReleaser($releaser);

        if ($this->laravel['config']->get('rabbitevents.failed_queue.enabled', true)) {
            $worker->setFailer(new Failer($context->makeFailedQueue($queueName), $context->createProducer()));
        }

        if ($this->laravel['config']->get('rabbitevents.kill_switch.enabled', false)) {
            $worker->setKillSwitch($this->makeKillSwitch());
        }

        return $worker->work(
            new Processor(
                $handlerFactory,
                $this->laravel['events'],
                $this->laravel[Dispatcher::class],
                $this->laravel->make(Deduplicator::class)
            ),
            $context->makeConsumer($queue),
            $options
        )->value;
    }

    /**
     * Gather all the queue worker options as a single object.
     *
     * @return ListenerOptions
     */
    protected function gatherOptions(): ListenerOptions
    {
        return new ListenerOptions(
            $this->resolveServiceName(),
            $this->option('connection') ?: $this->laravel['config']['rabbitevents.default'],
            $this->resolveEvents($this->argument('events')),
            (int)$this->option('memory'),
            (int)$this->option('tries'),
            (int)$this->option('timeout'),
            (int)$this->option('sleep'),
            $this->option('queue') ?: null,
        );
    }

    protected function makeKillSwitch(): KillSwitch
    {
        $config = $this->laravel['config'];

        return new KillSwitch(
            (int)$config->get('rabbitevents.kill_switch.activation_threshold', 10),
            (int)$config->get('rabbitevents.kill_switch.trip_threshold', 15),
            (int)$config->get('rabbitevents.kill_switch.tracking_period', 60),
            (int)$config->get('rabbitevents.kill_switch.restart_timeout', 60),
        );
    }

    /**
     * Listen for the queue events in order to update the console output.
     *
     * @return void
     */
    protected function listenForApplicationEvents(): void
    {
        $callback = function ($event) {
            foreach ($this->logWriters as $writer) {
                $writer->log($event);
            }
        };

        $this->laravel['events']->listen(ListenerHandling::class, $callback);
        $this->laravel['events']->listen(ListenerHandled::class, $callback);
        $this->laravel['events']->listen(ListenerHandleFailed::class, $callback);
        $this->laravel['events']->listen(ListenerHandlerExceptionOccurred::class, $callback);
        $this->laravel['events']->listen(MessageProcessingFailed::class, function ($event) {
            $this->output->error('Message processing failed with the exception: ' . $event->exception->getMessage());
        });
        $this->laravel['events']->listen(KillSwitchTripped::class, function ($event) {
            $this->output->warning(
                "Kill switch tripped: too many messages failed. Consuming stopped for {$event->restartTimeout}s."
            );
        });
        $this->laravel['events']->listen(KillSwitchRestarted::class, function () {
            $this->output->info('Kill switch restarted: consuming again.');
        });
        $this->laravel['events']->listen(WorkerStopping::class, function ($event) {
            $this->output->info('Worker has been stopped with the status code ' . $event->status);
        });
    }

    /**
     * Register classes to write log output
     */
    protected function registerLogWriters(): void
    {
        if (!$this->output->isQuiet()) {
            $this->logWriters[] = new Log\Output($this->output);
        }

        $config = $this->laravel['config'];

        if ($config->get('rabbitevents.logging.enabled', false)) {
            $this->logWriters[] = new Log\General(
                $this->laravel,
                $config->get('rabbitevents.logging.level', 'info'),
                $config->get('rabbitevents.logging.channel')
            );
        }
    }

    /**
     * Determine if a listener is registered for the given event or matching wildcard.
     *
     * @param string $event
     * @param array<string> $registeredEvents
     * @return bool
     */
    protected function hasListenerForEvent(string $event, array $registeredEvents): bool
    {
        // Whatever the dispatcher would deliver this event to, matched the way it matches wildcards.
        if ($this->laravel[Dispatcher::class]->hasListeners($event)) {
            return true;
        }

        // A wildcard event binds the queue to every matching routing key (AMQP: "*" is one word, "#" any).
        $pattern = '/^' . str_replace(['\#', '\*'], ['.*', '[^.]+'], preg_quote($event, '/')) . '$/';

        foreach ($registeredEvents as $registeredEvent) {
            if (preg_match($pattern, $registeredEvent)) {
                return true;
            }
        }

        return false;
    }

    private function checkExtLoaded(): void
    {
        if (extension_loaded('amqp') && !class_exists('Enqueue\AmqpExt\AmqpConnectionFactory')) {
            $this->info("You have ext-amqp extension installed. Require enqueue/amqp-ext package to use it.");
            $this->info("The package can be installed via 'composer require enqueue/amqp-ext' command.");
        }
    }
}
