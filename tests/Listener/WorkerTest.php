<?php

namespace RabbitEvents\Tests\Listener;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Carbon;
use Mockery as m;
use RabbitEvents\Foundation\Consumer;
use RabbitEvents\Foundation\Exceptions\ConnectionLostException;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Dispatcher;
use RabbitEvents\Listener\Events\KillSwitchRestarted;
use RabbitEvents\Listener\Events\KillSwitchTripped;
use RabbitEvents\Listener\Events\MessageProcessingFailed;
use RabbitEvents\Listener\Events\WorkerStopping;
use RabbitEvents\Listener\ListenerOptions;
use RabbitEvents\Listener\Message\HandlerFactory;
use RabbitEvents\Listener\Message\Processor;
use RabbitEvents\Listener\Support\Failer;
use RabbitEvents\Listener\Support\KillSwitch;
use RabbitEvents\Listener\Support\Releaser;
use RabbitEvents\Listener\Worker;
use RabbitEvents\Listener\WorkerExitStatus;
use RabbitEvents\Tests\Foundation\Stubs\DestinationStub;
use RabbitEvents\Tests\Foundation\Stubs\FailedDestinationStub;
use RabbitEvents\Tests\Foundation\Stubs\ProducerStub;
use RabbitEvents\Tests\Foundation\Stubs\QueueConsumerStub;
use RabbitEvents\Tests\MocksCommonObjects;

class WorkerTest extends TestCase
{
    use MocksCommonObjects;

    public $events;
    public $exceptionHandler;

    private $options;

    protected function setUp(): void
    {
        $this->events = m::spy(Dispatcher::class);
        $this->exceptionHandler = m::spy(ExceptionHandler::class);
        $this->options = $this->createListenerOptions();

        Container::setInstance($container = new Container);

        $container->instance(ExceptionHandler::class, $this->exceptionHandler);
        WorkerTestListener::$calls = 0;
        WorkerTestListener::$onHandle = null;
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    public function testInstantiable(): void
    {
        self::assertInstanceOf(Worker::class, new Worker($this->exceptionHandler, $this->events));
    }

    public function testWork(): void
    {
        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->shouldQuit = true; //For one tick only
        [$consumer, $queue] = $this->consumer($this->publishedMessage());

        $status = $worker->work($this->processor(WorkerTestListener::class), $consumer, $this->options);

        self::assertEquals(WorkerExitStatus::SUCCESS, $status);
        self::assertSame(1, WorkerTestListener::$calls);
        self::assertCount(1, $queue->acknowledged);
        $this->events->shouldHaveReceived()->dispatch(m::type(WorkerStopping::class))->once();
    }

    public function testStopIfMemoryLimitExceeded(): void
    {
        $worker = new Worker($this->exceptionHandler, $this->events);
        [$consumer, $queue] = $this->consumer($this->publishedMessage(), $this->publishedMessage());

        $status = $worker->work(
            $this->processor(WorkerTestListener::class),
            $consumer,
            $this->createListenerOptions(['memory' => 0])
        );

        self::assertEquals(WorkerExitStatus::MEMORY_LIMIT, $status);
        self::assertCount(1, $queue->acknowledged, 'Stops after the message it handled');
        $this->events->shouldHaveReceived()->dispatch(m::type(WorkerStopping::class))->once();
    }

    public function testStopListeningIfLostConnection(): void
    {
        $worker = new Worker($this->exceptionHandler, $this->events);
        [$consumer, $queue] = $this->consumer();
        $queue->onReceive = fn() => throw new \RuntimeException('Connection reset by peer');

        $status = $worker->work($this->processor(WorkerTestListener::class), $consumer, $this->options);

        self::assertEquals(WorkerExitStatus::SUCCESS, $status);

        $this->exceptionHandler->shouldHaveReceived()->report(m::type(ConnectionLostException::class));
        $this->events->shouldHaveReceived()->dispatch(m::type(WorkerStopping::class))->once();
    }

    public function testMessageInterruptedOutsideListenersIsReleasedInsteadOfAcknowledgedAsHandled(): void
    {
        $exception = new \RuntimeException('Deduplication store failed');
        $message = $this->publishedMessage();
        $message->setProperty('x-succeeded-listeners', ['App\\Listeners\\First@handle']);
        $releases = new ProducerStub();
        [$consumer, $queue] = $this->consumer($message);
        // The copy must be in the queue before the original is acknowledged.
        $queue->onAcknowledge = fn() => self::assertCount(1, $releases->sent);

        $this->workOnce($this->processorThrowing($exception, $releases), $consumer, $this->releaser($releases));

        $this->exceptionHandler->shouldHaveReceived()->report($exception);
        self::assertCount(1, $queue->acknowledged);
        self::assertSame([], $queue->rejected);
        // Listeners that already handled it skip the released copy.
        self::assertSame(
            ['App\\Listeners\\First@handle'],
            $releases->sent[0]['message']->getProperty('x-succeeded-listeners')
        );
    }

    public function testMessageAlreadyReleasedByItsListenerIsOnlyAcknowledged(): void
    {
        $releases = new ProducerStub();
        [$consumer, $queue] = $this->consumer($this->publishedMessage());

        $this->workOnce(
            $this->listenerFailing(new \RuntimeException('Listener failed'), $releases),
            $consumer,
            $this->releaser($releases)
        );

        self::assertSame(1, WorkerTestListener::$calls);
        self::assertCount(1, $releases->sent, 'Released by its listener only, not again by the worker');
        self::assertCount(1, $queue->acknowledged);
    }

    public function testInterruptedMessageIsRequeuedWithoutReleaser(): void
    {
        [$consumer, $queue] = $this->consumer($this->publishedMessage());

        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->shouldQuit = true; // one tick only

        // Requeued as it was received, so the worker stops (see the tests below).
        self::assertSame(
            WorkerExitStatus::ERROR,
            $worker->work($this->processorThrowing(new \RuntimeException()), $consumer, $this->options)
        );
        self::assertSame([], $queue->acknowledged);
        self::assertSame([true], array_column($queue->rejected, 'requeue'));
    }

    public static function messagesThatCannotBeReturned(): array
    {
        return [
            // Its listener failed, and neither its handler nor the worker can release it.
            'cannot be released' => [FailingWorkerTestListener::class, false, true, false, 2],
            // Interrupted outside its listeners, and the worker has no releaser.
            'no releaser' => [WorkerTestListener::class, true, false, false, 2, false],
            // Its listener used up its tries, and it can't be moved to the failed queue.
            'cannot be failed' => [OneTryWorkerTestListener::class, false, false, true, 2],
            'interrupted on its last delivery, cannot be failed' => [WorkerTestListener::class, true, false, true, 1],
        ];
    }

    /**
     * A requeued original comes back to the head of the queue with the counters it was received with, so
     * `--tries` can never stop it: the worker would handle it again at once, forever, running even the listeners
     * that had handled it. The worker stops instead; the process manager restarts it later.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('messagesThatCannotBeReturned')]
    public function testWorkerStopsWhenMessageCanBeOnlyRequeued(
        string $listener,
        bool $interruptedOutsideListeners,
        bool $releaseFails,
        bool $failFails,
        int $maxTries,
        bool $workerReleases = true
    ): void {
        $publishFailed = new \RuntimeException('PRECONDITION_FAILED');
        $releases = new ProducerStub();
        $failures = new ProducerStub();
        if ($releaseFails) {
            $releases->throwException($publishFailed);
        }
        if ($failFails) {
            $failures->throwException($publishFailed);
        }
        FailingWorkerTestListener::$exception = new \RuntimeException('Listener failed');
        $processor = $interruptedOutsideListeners
            ? $this->processorThrowing(new \RuntimeException('Deduplication store failed'), $releases)
            : $this->processor($listener, $releases);
        [$consumer, $queue] = $this->consumer($this->publishedMessage()); // its first delivery

        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->setReleaser($workerReleases ? $this->releaser($releases) : null)->setFailer($this->failer($failures));
        // Without the fix the worker takes the requeued message again: end the test instead of looping.
        $queue->onReceive = function (int $receives) use ($worker) {
            if ($receives > 1) {
                $worker->shouldQuit = true;
            }
        };
        $status = $worker->work($processor, $consumer, $this->createListenerOptions(['maxTries' => $maxTries]));

        self::assertSame(WorkerExitStatus::ERROR, $status);
        self::assertSame(1, $queue->receives, 'The requeued message must not be taken again');
        self::assertSame([], $queue->acknowledged);
        self::assertSame([true], array_column($queue->rejected, 'requeue'));
        self::assertCount(1, $queue->messages, 'Back in the queue as it was received');
        self::assertSame([], $releases->sent);
        self::assertSame([], $failures->sent);
    }

    private function workOnce(Processor $processor, Consumer $consumer, ?Releaser $releaser = null): void
    {
        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->setReleaser($releaser);
        $worker->shouldQuit = true; // one tick only

        self::assertEquals(WorkerExitStatus::SUCCESS, $worker->work($processor, $consumer, $this->options));
    }

    /**
     * The real Processor, Dispatcher, HandlerFactory and Releaser: only the broker is stubbed (`$releases` records what
     * the releaser publishes, and can fail).
     */
    private function processor(
        string $listener,
        ?ProducerStub $releases = null,
        ?\RabbitEvents\Listener\Contracts\Deduplicator $deduplicator = null
    ): Processor {
        $app = Container::getInstance();
        $dispatcher = new Dispatcher($app);
        $dispatcher->listen('order.created', $listener);

        return new Processor(
            new HandlerFactory($app, $this->releaser($releases ?? new ProducerStub())),
            $this->events,
            $dispatcher,
            $deduplicator
        );
    }

    /**
     * Processing fails outside the listeners: the deduplication store is unreachable before they run.
     */
    private function processorThrowing(\Throwable $exception, ?ProducerStub $releases = null): Processor
    {
        return $this->processor(WorkerTestListener::class, $releases, new class ($exception) implements \RabbitEvents\Listener\Contracts\Deduplicator {
            public function __construct(private \Throwable $exception)
            {
            }

            public function isDuplicate(Message $message, string $queue): bool
            {
                throw $this->exception;
            }

            public function markHandled(Message $message, string $queue): void
            {
            }
        });
    }

    /**
     * A listener failed: Handler::release() returned the message to the queue, and the exception reaches the worker.
     */
    private function listenerFailing(\Throwable $exception, ?ProducerStub $releases = null): Processor
    {
        FailingWorkerTestListener::$exception = $exception;

        return $this->processor(FailingWorkerTestListener::class, $releases);
    }

    private function releaser(ProducerStub $producer): Releaser
    {
        return new Releaser(new DestinationStub('orders'), $producer);
    }

    private function failer(ProducerStub $producer): Failer
    {
        return new Failer(new FailedDestinationStub('orders'), $producer);
    }

    /**
     * The real Consumer over a queue that behaves as RabbitMQ does: a requeued message comes back to its head.
     *
     * @return array{Consumer, QueueConsumerStub}
     */
    private function consumer(Message ...$messages): array
    {
        $registry = new \RabbitEvents\Foundation\Serialization\SerializerRegistry();
        $registry->register(new \RabbitEvents\Foundation\Serialization\JsonSerializer());
        $queue = new class (array_map(fn(Message $message) => $message->transportMessage(), $messages)) extends QueueConsumerStub {
            public int $receives = 0;
            public ?\Closure $onReceive = null;
            public ?\Closure $onAcknowledge = null;

            public function receive(int $timeout = 0): ?\RabbitEvents\Foundation\Contracts\TransportMessage
            {
                // A worker that doesn't stop when it should fails the test instead of hanging it.
                if (++$this->receives > 20) {
                    throw new \LogicException('The worker keeps polling');
                }

                $this->onReceive && ($this->onReceive)($this->receives);

                return parent::receive($timeout);
            }

            public function acknowledge(\RabbitEvents\Foundation\Contracts\TransportMessage $message): void
            {
                $this->onAcknowledge && ($this->onAcknowledge)();
                parent::acknowledge($message);
            }
        };

        return [new Consumer($queue, $registry), $queue];
    }

    /**
     * As published: Consumer::nextMessage() counts the delivery.
     */
    private function publishedMessage(int $deliveries = 0): Message
    {
        $message = new Message('order.created', new \RabbitEvents\Foundation\Support\JsonPayload(['id' => 1]), ['message_id' => 'msg-1']);
        $message->setProperty('x-attempts', $deliveries);

        return $message;
    }

    public function testKillSwitchStopsConsumingForRestartTimeoutWhenTooManyMessagesFail(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $log = [];

        $worker = new TestWorker($this->exceptionHandler, $this->events);
        $worker->setKillSwitch(new KillSwitch(activationThreshold: 2, tripThreshold: 100, restartTimeout: 3));
        $worker->onSleep = function (int $seconds) use (&$log) {
            $log[] = "sleep $seconds";
            Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
        };

        [$consumer, $queue] = $this->consumer($this->publishedMessage(), $this->publishedMessage());
        $queue->onReceive = function () use (&$log, $worker, $queue) {
            $log[] = 'next';

            if ($queue->messages === []) {
                $worker->shouldQuit = true;
            }
        };

        $worker->work($this->listenerFailing(new \RuntimeException('Database is down')), $consumer, $this->options);

        self::assertSame(['next', 'next', 'sleep 1', 'sleep 1', 'sleep 1', 'next'], $log);
        $this->events->shouldHaveReceived('dispatch')->with(m::on(
            fn($event) => $event instanceof KillSwitchTripped && $event->restartTimeout === 3
        ))->once();
        $this->events->shouldHaveReceived('dispatch')->with(m::type(KillSwitchRestarted::class))->once();

        Carbon::setTestNow();
    }

    public function testKillSwitchIsNotTrippedBySucceededMessages(): void
    {
        $worker = new TestWorker($this->exceptionHandler, $this->events);
        $worker->setKillSwitch(new KillSwitch(activationThreshold: 1, tripThreshold: 1));
        $worker->onSleep = fn() => self::fail('The worker must not pause');

        [$consumer, $queue] = $this->consumer($this->publishedMessage(), $this->publishedMessage());
        $queue->onReceive = function () use ($worker, $queue) {
            if ($queue->messages === []) {
                $worker->shouldQuit = true;
            }
        };

        $worker->work($this->processor(WorkerTestListener::class), $consumer, $this->options);

        self::assertSame(2, WorkerTestListener::$calls);
        self::assertCount(2, $queue->acknowledged);
        $this->events->shouldNotHaveReceived('dispatch', [m::type(KillSwitchTripped::class)]);
    }

    public function testWorkerStoppedWhilePausedQuitsWithoutWaitingForRestart(): void
    {
        $worker = new TestWorker($this->exceptionHandler, $this->events);
        $worker->setKillSwitch(new KillSwitch(activationThreshold: 1, tripThreshold: 100, restartTimeout: 60));
        $slept = 0;
        $worker->onSleep = function () use (&$slept, $worker) {
            $slept++;
            $worker->shouldQuit = true; // SIGTERM
        };

        [$consumer, $queue] = $this->consumer($this->publishedMessage());

        $status = $worker->work($this->listenerFailing(new \RuntimeException()), $consumer, $this->options);

        self::assertSame(WorkerExitStatus::SUCCESS, $status);
        self::assertSame(1, $slept);
        $this->events->shouldNotHaveReceived('dispatch', [m::type(KillSwitchRestarted::class)]);
    }

    public function testWorkerOverMemoryLimitExitsInsteadOfPausing(): void
    {
        $worker = new TestWorker($this->exceptionHandler, $this->events);
        $worker->setKillSwitch(new KillSwitch(activationThreshold: 1, tripThreshold: 100));
        $worker->onSleep = fn() => self::fail('The worker must not pause');

        [$consumer, $queue] = $this->consumer($this->publishedMessage());

        $status = $worker->work(
            $this->listenerFailing(new \RuntimeException()),
            $consumer,
            $this->createListenerOptions(['memory' => 0])
        );

        self::assertSame(WorkerExitStatus::MEMORY_LIMIT, $status);
        $this->events->shouldNotHaveReceived('dispatch', [m::type(KillSwitchTripped::class)]);
    }

    public function testWorkerStoppedWhileHandlingTheTrippingMessageExitsInsteadOfPausing(): void
    {
        $worker = new TestWorker($this->exceptionHandler, $this->events);
        $worker->setKillSwitch(new KillSwitch(activationThreshold: 1, tripThreshold: 100));
        $worker->onSleep = fn() => self::fail('The worker must not pause');

        [$consumer, $queue] = $this->consumer($this->publishedMessage());

        WorkerTestListener::$onHandle = function () use ($worker) {
            $worker->shouldQuit = true; // SIGTERM
        };

        self::assertSame(
            WorkerExitStatus::SUCCESS,
            $worker->work($this->listenerFailing(new \RuntimeException()), $consumer, $this->options)
        );
        $this->events->shouldNotHaveReceived('dispatch', [m::type(KillSwitchTripped::class)]);
    }

    public function testWorkerOverMemoryLimitAfterPauseExitsWithoutFetchingAnotherMessage(): void
    {
        $worker = new TestWorker($this->exceptionHandler, $this->events);
        $worker->setKillSwitch(new KillSwitch(activationThreshold: 1, tripThreshold: 100, restartTimeout: 1));
        $worker->onSleep = fn() => Carbon::setTestNow(Carbon::now()->addSecond());
        $worker->memoryExceededAfterSleep = true;

        [$consumer, $queue] = $this->consumer($this->publishedMessage());

        $status = $worker->work($this->listenerFailing(new \RuntimeException()), $consumer, $this->options);

        self::assertSame(WorkerExitStatus::MEMORY_LIMIT, $status);
        self::assertSame(1, $queue->receives);
        Carbon::setTestNow();
    }

    public function testMessageDeliveredMoreTimesThanWorkerTriesIsProcessed(): void
    {
        // Its listeners may allow more tries than --tries: the processor decides per listener. Retries of other
        // listeners count as deliveries, not as attempts of this one.
        [$consumer, $queue] = $this->consumer($this->publishedMessage(deliveries: 3));

        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->shouldQuit = true; //one tick
        $worker->work($this->processor(WorkerTestListener::class), $consumer, $this->createListenerOptions(['maxTries' => 2]));

        self::assertSame(1, WorkerTestListener::$calls);
        self::assertCount(1, $queue->acknowledged);
    }

    public function testInterruptedMessageOnItsLastDeliveryIsFailedInsteadOfReleased(): void
    {
        $exception = new \RuntimeException('Deduplication store failed');
        $releases = new ProducerStub();
        $failures = new ProducerStub();
        [$consumer, $queue] = $this->consumer($this->publishedMessage(deliveries: 1)); // its second delivery
        $queue->onAcknowledge = fn() => self::assertCount(1, $failures->sent, 'Failed before acknowledged');

        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->setReleaser($this->releaser($releases))->setFailer($this->failer($failures));
        $worker->shouldQuit = true;
        $worker->work($this->processorThrowing($exception, $releases), $consumer, $this->createListenerOptions(['maxTries' => 2]));

        self::assertSame([], $releases->sent);
        self::assertSame('Deduplication store failed', $failures->sent[0]['message']->getProperty('x-exception-message'));
        self::assertCount(1, $queue->acknowledged);
        $this->events->shouldHaveReceived()->dispatch(m::type(MessageProcessingFailed::class))->once();
    }

    public function testExitIfTimedOut(): void
    {
        $failures = new ProducerStub();
        [$consumer] = $this->consumer($this->publishedMessage());
        WorkerTestListener::$onHandle = fn() => sleep(2); // a stuck listener

        $worker = new TestWorker($this->exceptionHandler, $this->events);
        $worker->setFailer($this->failer($failures));
        $worker->shouldQuit = true;
        $worker->work($this->processor(WorkerTestListener::class), $consumer, $this->createListenerOptions(['timeout' => 1]));

        self::assertEquals(WorkerExitStatus::ERROR, $worker->exitStatus);
        // TestWorker::kill() doesn't end the process, so the worker goes on after it; the first copy is the timeout's.
        self::assertSame(
            \RabbitEvents\Listener\Exceptions\TimeoutExceededException::class,
            $failures->sent[0]['message']->getProperty('x-exception-class')
        );
        $this->events->shouldHaveReceived()->dispatch(m::type(MessageProcessingFailed::class))->once();
    }

    public function testTimeoutResetIfProcessEndedWithException(): void
    {
        [$consumer, $queue] = $this->consumer($this->publishedMessage());

        $worker = new TestWorker($this->exceptionHandler, $this->events);
        $worker->shouldQuit = true;
        $worker->work(
            $this->processorThrowing(new \RuntimeException('Stopped unexpectedly')),
            $consumer,
            $this->createListenerOptions(['timeout' => 1])
        );

        self::assertTrue($worker->resetTimeoutHandler);
        // Interrupted and no releaser: returned to the queue, not acknowledged.
        self::assertSame([true], array_column($queue->rejected, 'requeue'));
    }

    public function testWorkUsesFailerWhenMessageHasFailed(): void
    {
        $app = Container::getInstance();
        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->shouldQuit = true;

        $dlxProducer = new \RabbitEvents\Tests\Foundation\Stubs\ProducerStub();
        $failer = new \RabbitEvents\Listener\Support\Failer(
            new \RabbitEvents\Tests\Foundation\Stubs\FailedDestinationStub('orders-queue'),
            $dlxProducer
        );
        $worker->setFailer($failer);

        $dispatcher = new Dispatcher($app);
        $dispatcher->listen('order.failed', static function () {
            throw new \RuntimeException('Permanent listener failure');
        });

        $handlerFactory = new \RabbitEvents\Listener\Message\HandlerFactory(
            $app,
            new \RabbitEvents\Listener\Support\Releaser(
                new \RabbitEvents\Tests\Foundation\Stubs\DestinationStub('orders-queue'),
                new \RabbitEvents\Tests\Foundation\Stubs\ProducerStub()
            )
        );
        $processor = new Processor($handlerFactory, new \Illuminate\Events\Dispatcher($app), $dispatcher);

        $registry = new \RabbitEvents\Foundation\Serialization\SerializerRegistry();
        $registry->register(new \RabbitEvents\Foundation\Serialization\JsonSerializer());

        $incomingMessage = new Message('order.failed', new \RabbitEvents\Foundation\Support\JsonPayload(['id' => 1]), ['message_id' => 'msg-w1']);
        $queueConsumer = new \RabbitEvents\Tests\Foundation\Stubs\QueueConsumerStub([$incomingMessage->transportMessage()]);
        $consumer = new Consumer($queueConsumer, $registry);

        $options = $this->createListenerOptions(['maxTries' => 1]);

        $status = $worker->work($processor, $consumer, $options);

        self::assertEquals(WorkerExitStatus::SUCCESS, $status);
        self::assertCount(1, $dlxProducer->sent, 'Failer must send failed message to DLX');
        self::assertSame('Permanent listener failure', $dlxProducer->sent[0]['message']->getProperty('x-exception-message'));
        self::assertSame('order.failed', $dlxProducer->sent[0]['message']->getProperty('x-original-routing-key'));
        self::assertCount(1, $queueConsumer->acknowledged, 'Original message must be acknowledged after Failer publishes to DLX');
        self::assertCount(0, $queueConsumer->rejected, 'Original message must NOT be rejected when Failer handles it');
    }
}

class TestWorker extends Worker
{
    public $exitStatus;
    public $resetTimeoutHandler = false;
    public ?\Closure $onSleep = null;
    public bool $memoryExceededAfterSleep = false;
    private bool $slept = false;

    public function kill(\RabbitEvents\Listener\WorkerExitStatus|int $status = 0): void
    {
        $this->exitStatus = $status;
    }

    protected function resetTimeoutHandler()
    {
        $this->resetTimeoutHandler = true;
    }

    protected function sleep(int $seconds): void
    {
        $this->slept = true;
        ($this->onSleep)($seconds);
    }

    protected function memoryExceeded(int $memoryLimit): bool
    {
        return ($this->memoryExceededAfterSleep && $this->slept) || parent::memoryExceeded($memoryLimit);
    }
}

class WorkerTestListener
{
    public static int $calls = 0;

    public static ?\Closure $onHandle = null;

    public function handle(array $payload): void
    {
        self::$calls++;

        if (self::$onHandle) {
            (self::$onHandle)();
        }
    }
}

/**
 * Fails until its tries are used up; `$tries = 0`: retried for ever.
 */
class FailingWorkerTestListener extends WorkerTestListener
{
    public static \Throwable $exception;

    public int $tries = 0;

    public function handle(array $payload): void
    {
        parent::handle($payload);

        throw self::$exception;
    }
}

class OneTryWorkerTestListener extends FailingWorkerTestListener
{
    public int $tries = 1;
}
