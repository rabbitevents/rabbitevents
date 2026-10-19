<?php

namespace RabbitEvents\Tests\Listener;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Mockery as m;
use RabbitEvents\Foundation\Consumer;
use RabbitEvents\Foundation\Exceptions\ConnectionLostException;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Dispatcher;
use RabbitEvents\Listener\Events\MessageProcessingFailed;
use RabbitEvents\Listener\Events\WorkerStopping;
use RabbitEvents\Listener\ListenerOptions;
use RabbitEvents\Listener\Message\Processor;
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

    public function testInterruptedMessageIsRequeuedWhenItCannotBeReleased(): void
    {
        $message = $this->createRealMessage();
        $releaser = m::mock(Releaser::class);
        $releaser->shouldReceive('release')->andThrow(new \RuntimeException('Broker unavailable'));

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->andReturn($message);
        $consumer->shouldNotReceive('acknowledge');
        $consumer->shouldReceive('reject')->once()->with($message, true);

        $this->workOnce($this->processorThrowing(new \RuntimeException()), $consumer, $releaser);
    }

    public function testInterruptedMessageIsRequeuedWithoutReleaser(): void
    {
        $message = $this->createRealMessage();
        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive('nextMessage')->andReturn($message);
        $consumer->shouldNotReceive('acknowledge');
        $consumer->shouldReceive('reject')->once()->with($message, true);

        $this->workOnce($this->processorThrowing(new \RuntimeException()), $consumer);
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

    private function createRealMessage(): Message
    {
        return (new Message('order.created', new \RabbitEvents\Foundation\Support\JsonPayload(['id' => 1])))
            ->setTransportMessage(new \RabbitEvents\Tests\Foundation\Stubs\TransportMessageStub('{"id":1}', ['x-attempts' => 1]));
    }

    public function testProcessNotStartedIfExceededMaxAttempts(): void
    {
        $message = $this->createMessageMock();
        $message->shouldReceive('attempts')
            ->andReturn(3);
        $message->shouldReceive('markAsFailed')->once();
        $message->shouldReceive('hasFailed')->andReturn(true);

        $consumer = m::mock(Consumer::class);
        $consumer->shouldReceive()
            ->nextMessage(1000)
            ->andReturn($message);
        $consumer->shouldReceive('reject')->with($message, false)->once();

        $processor = m::spy(Processor::class);

        $worker = new Worker($this->exceptionHandler, $this->events);
        $worker->shouldQuit = true; //one tick

        $options = $this->createListenerOptions(['maxTries' => 2]);

        $worker->work($processor, $consumer, $options);

        $processor->shouldNotHaveReceived('process');

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

    public function kill(\RabbitEvents\Listener\WorkerExitStatus|int $status = 0): void
    {
        $this->exitStatus = $status;
    }

    protected function resetTimeoutHandler()
    {
        $this->resetTimeoutHandler = true;
    }
}
