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
use RabbitEvents\Listener\Message\Processor;
use RabbitEvents\Listener\Support\KillSwitch;
use RabbitEvents\Listener\Support\Releaser;
use RabbitEvents\Listener\Worker;
use RabbitEvents\Listener\WorkerExitStatus;
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
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    protected function createMessageMock(): Message
    {
        $message = m::mock(Message::class);
        $message->shouldReceive('hasFailed')->byDefault()->andReturn(false);
        $message->shouldReceive('isReleased')->byDefault()->andReturn(false);

        return $message;
    }

    public function testInstantiable(): void
    {
        self::assertInstanceOf(Worker::class, new Worker($this->exceptionHandler, $this->events));
    }

    public function testWork(): void
    {
        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->shouldQuit = true; //For one tick only

        $processor = m::spy(Processor::class);
        $message = $this->createMessageMock();
        $consumer = m::mock(Consumer::class)->makePartial();
        $consumer->shouldReceive('nextMessage')
            ->andReturn($message);
        $consumer->shouldReceive('acknowledge')->once();

        $status = $worker->work($processor, $consumer, $this->options);

        self::assertEquals(WorkerExitStatus::SUCCESS, $status);

        $processor->shouldHaveReceived()->process($message, $this->options);
        $this->events->shouldHaveReceived()->dispatch(m::type(WorkerStopping::class))->once();
    }

    public function testStopIfMemoryLimitExceeded(): void
    {
        $worker = new Worker($this->exceptionHandler, $this->events);
        $options = $this->createListenerOptions(['memory' => 0]);

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')
            ->andReturn($this->createMessageMock());
        $consumer->shouldReceive('acknowledge')->once();

        $status = $worker->work(m::spy(Processor::class), $consumer, $options);

        self::assertEquals(WorkerExitStatus::MEMORY_LIMIT, $status);
        $this->events->shouldHaveReceived()->dispatch(m::type(WorkerStopping::class))->once();
    }

    public function testStopListeningIfLostConnection(): void
    {
        $exception = new ConnectionLostException();

        $worker = new Worker($this->exceptionHandler, $this->events);

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')
            ->andThrow($exception);

        $status = $worker->work(m::mock(Processor::class), $consumer, $this->options);

        self::assertEquals(WorkerExitStatus::SUCCESS, $status);

        $this->exceptionHandler->shouldHaveReceived()->report($exception);
        $this->events->shouldHaveReceived()->dispatch(m::type(WorkerStopping::class))->once();
    }

    public function testMessageInterruptedOutsideListenersIsReleasedInsteadOfAcknowledgedAsHandled(): void
    {
        $exception = new \RuntimeException('Deduplication store failed');
        $message = $this->createRealMessage();
        $message->setProperty('x-succeeded-listeners', ['App\\Listeners\\First']);

        $releaser = m::mock(Releaser::class);
        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->andReturn($message);
        // The copy must be in the queue before the original is acknowledged.
        $releaser->shouldReceive('release')->once()->with($message, $this->options->sleep)->ordered()
            ->andReturnUsing(fn(Message $released) => $released->markAsReleased());
        $consumer->shouldReceive('acknowledge')->once()->with($message)->ordered();
        $consumer->shouldNotReceive('reject');

        $this->workOnce($this->processorThrowing($exception), $consumer, $releaser);

        $this->exceptionHandler->shouldHaveReceived()->report($exception);
        // Listeners that already handled it skip the released copy.
        self::assertSame(['App\\Listeners\\First'], $message->getProperty('x-succeeded-listeners'));
    }

    public function testMessageAlreadyReleasedByItsListenerIsOnlyAcknowledged(): void
    {
        $message = $this->createRealMessage();
        $releaser = m::mock(Releaser::class);
        $releaser->shouldNotReceive('release');

        $processor = m::mock(Processor::class);
        $processor->shouldReceive('process')->andReturnUsing(function (Message $message) {
            $message->markAsReleased(); // what Handler::release() does before rethrowing the listener exception
            throw new \RuntimeException('Listener failed');
        });

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->andReturn($message);
        $consumer->shouldReceive('acknowledge')->once()->with($message);

        $this->workOnce($processor, $consumer, $releaser);
    }

    public function testInterruptedMessageIsRequeuedWithoutReleaser(): void
    {
        $message = $this->createRealMessage();
        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->andReturn($message);
        $consumer->shouldNotReceive('acknowledge');
        $consumer->shouldReceive('reject')->once()->with($message, true);

        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->shouldQuit = true; // one tick only

        // Requeued as it was received, so the worker stops (see the tests below).
        self::assertSame(
            WorkerExitStatus::ERROR,
            $worker->work($this->processorThrowing(new \RuntimeException()), $consumer, $this->options)
        );
    }

    public static function messagesThatCannotBeReturned(): array
    {
        $publishFailed = new \RuntimeException('PRECONDITION_FAILED on the delay queue');

        return [
            // Interrupted (e.g. Handler::release() threw), and the worker can't release it either.
            'cannot be released' => [fn() => throw $publishFailed, null, 2, false],
            'no releaser' => [null, null, 2, false],
            // A listener used up its tries, and the message can't be moved to the failed queue.
            'cannot be failed' => [null, fn() => throw $publishFailed, 2, true],
            'interrupted on its last delivery, cannot be failed' => [
                fn() => throw new \LogicException('Must be failed, not released'),
                fn() => throw $publishFailed,
                1,
                false,
            ],
        ];
    }

    /**
     * A requeued original comes back to the head of the queue with the counters it was received with, so
     * `--tries` can never stop it: the worker would handle it again at once, forever, running even the listeners
     * that had handled it. The worker stops instead; the process manager restarts it later.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('messagesThatCannotBeReturned')]
    public function testWorkerStopsWhenMessageCanBeOnlyRequeued(
        ?\Closure $release,
        ?\Closure $fail,
        int $maxTries,
        bool $listenerFailed
    ): void
    {
        $message = $this->createRealMessage(); // its first delivery

        $releaser = null;
        if ($release) {
            $releaser = m::mock(Releaser::class);
            $releaser->shouldReceive('release')->andReturnUsing($release);
        }

        $failer = null;
        if ($fail) {
            $failer = m::mock(\RabbitEvents\Foundation\Contracts\Transport::class);
            $failer->shouldReceive('send')->andReturnUsing($fail);
        }

        $deliveries = 0;
        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->andReturnUsing(function () use (&$deliveries, $message) {
            // Without the fix the worker takes the requeued message again; end the test instead of looping.
            return $deliveries++ === 0 ? $message : throw new ConnectionLostException();
        });
        $consumer->shouldNotReceive('acknowledge');
        $consumer->shouldReceive('reject')->once()->with($message, true);

        $processor = m::mock(Processor::class);
        $processor->shouldReceive('process')->andReturnUsing(function (Message $message) use ($listenerFailed) {
            $exception = new \RuntimeException('Listener failed');

            if ($listenerFailed) {
                $message->markAsFailed($exception); // what Processor does when a listener used up its tries
            }

            throw $exception;
        });

        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->setReleaser($releaser)->setFailer($failer);
        $status = $worker->work($processor, $consumer, $this->createListenerOptions(['maxTries' => $maxTries]));

        self::assertSame(WorkerExitStatus::ERROR, $status);
        self::assertSame(1, $deliveries);
        $this->exceptionHandler->shouldNotHaveReceived('report', [m::type(ConnectionLostException::class)]);
    }

    private function workOnce(Processor $processor, Consumer $consumer, ?Releaser $releaser = null): void
    {
        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->setReleaser($releaser);
        $worker->shouldQuit = true; // one tick only

        self::assertEquals(WorkerExitStatus::SUCCESS, $worker->work($processor, $consumer, $this->options));
    }

    private function processorThrowing(\Throwable $exception): Processor
    {
        $processor = m::mock(Processor::class);
        $processor->shouldReceive('process')->andThrow($exception);

        return $processor;
    }

    /**
     * A listener failed: Handler::release() returned the message to the queue, and the exception reaches the worker.
     */
    private function listenerFailing(\Throwable $exception): Processor
    {
        $processor = m::mock(Processor::class);
        $processor->shouldReceive('process')->andReturnUsing(function (Message $message) use ($exception) {
            $message->markAsReleased();

            throw $exception;
        });

        return $processor;
    }

    private function createRealMessage(): Message
    {
        return (new Message('order.created', new \RabbitEvents\Foundation\Support\JsonPayload(['id' => 1])))
            ->setTransportMessage(new \RabbitEvents\Tests\Foundation\Stubs\TransportMessageStub('{"id":1}', ['x-attempts' => 1]));
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

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->andReturnUsing(function () use (&$log, $worker) {
            $log[] = 'next';

            if (count(array_keys($log, 'next')) > 2) {
                $worker->shouldQuit = true;

                return null;
            }

            return $this->createRealMessage();
        });
        $consumer->shouldReceive('acknowledge');

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

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->times(3)->andReturn(
            $this->createRealMessage(),
            $this->createRealMessage(),
            null
        );
        $consumer->shouldReceive('acknowledge')->twice();
        $consumer->shouldReceive('nextMessage')->andReturnUsing(function () use ($worker) {
            $worker->shouldQuit = true;

            return null;
        });

        $worker->work(m::spy(Processor::class), $consumer, $this->options);

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

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->once()->andReturn($this->createRealMessage());
        $consumer->shouldReceive('acknowledge');

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

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->once()->andReturn($this->createRealMessage());
        $consumer->shouldReceive('acknowledge');

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

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->once()->andReturn($this->createRealMessage());
        $consumer->shouldReceive('acknowledge');

        $processor = m::mock(Processor::class);
        $processor->shouldReceive('process')->andReturnUsing(function (Message $message) use ($worker) {
            $worker->shouldQuit = true; // SIGTERM
            $message->markAsReleased();

            throw new \RuntimeException();
        });

        self::assertSame(WorkerExitStatus::SUCCESS, $worker->work($processor, $consumer, $this->options));
        $this->events->shouldNotHaveReceived('dispatch', [m::type(KillSwitchTripped::class)]);
    }

    public function testWorkerOverMemoryLimitAfterPauseExitsWithoutFetchingAnotherMessage(): void
    {
        $worker = new TestWorker($this->exceptionHandler, $this->events);
        $worker->setKillSwitch(new KillSwitch(activationThreshold: 1, tripThreshold: 100, restartTimeout: 1));
        $worker->onSleep = fn() => Carbon::setTestNow(Carbon::now()->addSecond());
        $worker->memoryExceededAfterSleep = true;

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->once()->andReturn($this->createRealMessage());
        $consumer->shouldReceive('acknowledge');

        $status = $worker->work($this->listenerFailing(new \RuntimeException()), $consumer, $this->options);

        self::assertSame(WorkerExitStatus::MEMORY_LIMIT, $status);
        Carbon::setTestNow();
    }

    public function testMessageDeliveredMoreTimesThanWorkerTriesIsProcessed(): void
    {
        // Its listeners may allow more tries than --tries: the processor decides per listener.
        $message = $this->createRealMessage();
        $message->setProperty('x-attempts', 3);

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->andReturn($message);
        $consumer->shouldReceive('acknowledge')->once()->with($message);

        $processor = m::spy(Processor::class);

        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->shouldQuit = true; //one tick
        $worker->work($processor, $consumer, $options = $this->createListenerOptions(['maxTries' => 2]));

        $processor->shouldHaveReceived('process')->with($message, $options)->once();
    }

    public function testInterruptedMessageOnItsLastDeliveryIsFailedInsteadOfReleased(): void
    {
        $exception = new \RuntimeException('Deduplication store failed');
        $message = $this->createRealMessage();
        $message->setProperty('x-attempts', 2);

        $releaser = m::mock(Releaser::class);
        $releaser->shouldNotReceive('release');
        $failer = m::mock(\RabbitEvents\Foundation\Contracts\Transport::class);
        $failer->shouldReceive('send')->once()->with($message);

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->andReturn($message);
        $consumer->shouldReceive('acknowledge')->once()->with($message);

        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->setReleaser($releaser)->setFailer($failer);
        $worker->shouldQuit = true;
        $worker->work($this->processorThrowing($exception), $consumer, $this->createListenerOptions(['maxTries' => 2]));

        self::assertTrue($message->hasFailed());
        self::assertSame($exception, $message->failureException());
        $this->events->shouldHaveReceived()->dispatch(m::type(MessageProcessingFailed::class))->once();
    }

    public function testExitIfTimedOut(): void
    {
        $message = $this->createMessageMock();
        $message->shouldReceive('markAsFailed')->once();

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')
            ->andReturn($message);

        $consumer->shouldReceive('reject')->with($message, false)->once();
        $consumer->shouldReceive('acknowledge')->once(); //The second is because of $shouldQuit === true

        $processor = m::mock(Processor::class);
        $processor->shouldReceive('process')
            ->andReturnUsing(function () {
                sleep(2);

                return true;
            });

        $options = $this->createListenerOptions(['timeout' => 1]);

        $worker = new TestWorker($this->exceptionHandler, $this->events);
        $worker->shouldQuit = true;
        $worker->work($processor, $consumer, $options);

        self::assertEquals(WorkerExitStatus::ERROR, $worker->exitStatus);

        $this->events->shouldHaveReceived()->dispatch(m::type(MessageProcessingFailed::class))->once();
    }

    public function testTimeoutResetIfProcessEndedWithException(): void
    {
        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')
            ->andReturn($this->createMessageMock());

        // Interrupted and no releaser: returned to the queue, not acknowledged.
        $consumer->shouldReceive('reject')->once()->with(m::type(Message::class), true);

        $processor = m::mock(Processor::class);
        $processor->shouldReceive('process')
            ->andThrow(new \RuntimeException('Stopped unexpectedly'));

        $options = $this->createListenerOptions(['timeout' => 1]);

        $worker = new TestWorker($this->exceptionHandler, $this->events);
        $worker->shouldQuit = true;
        $worker->work($processor, $consumer, $options);

        self::assertTrue($worker->resetTimeoutHandler);
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
