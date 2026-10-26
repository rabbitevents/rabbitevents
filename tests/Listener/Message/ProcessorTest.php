<?php

namespace RabbitEvents\Tests\Listener\Message;
use RabbitEvents\Listener\Dispatcher as RabbitEventsDispatcher;
use Illuminate\Events\Dispatcher;
use Mockery as m;

use RabbitEvents\Foundation\Contracts\Transport;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Events\ListenerHandled;
use RabbitEvents\Listener\Events\ListenerHandleFailed;
use RabbitEvents\Listener\Events\ListenerHandlerExceptionOccurred;
use RabbitEvents\Listener\Events\ListenerHandling;
use RabbitEvents\Listener\Exceptions\FailedException;

use RabbitEvents\Listener\ListenerOptions;
use RabbitEvents\Listener\Message\Handler;
use RabbitEvents\Listener\Message\HandlerFactory;
use RabbitEvents\Listener\Message\Processor;
use RabbitEvents\Tests\Listener\Payload;
use RabbitEvents\Tests\Listener\TestCase;

class ProcessorTest extends TestCase
{
    private $message;
    private $events;
    private $dispatcher;

    public function setUp(): void
    {
        parent::setUp();

        $this->events = m::spy(Dispatcher::class);
        $this->dispatcher = m::mock(RabbitEventsDispatcher::class);
        $this->message = new Message('test.event', new Payload(['test' => 'payload']));
    }

    public function testProcess()
    {
        $this->mockListeners([
            static fn() => true,
            new FakeHandler($this->message), // Using an invokable object to test class name resolution
        ]);

        $processor = new Processor($handlerFactory = new FakeHandlerFactory(), $this->events, $this->dispatcher);

        $processor->process($this->message, $this->options());

        self::assertCount(2, $handlerFactory->handlers);

        foreach ($handlerFactory->handlers as $handler) {
            self::assertTrue($handler->fired);
        }

        $this->events->shouldHaveReceived()
            ->dispatch(m::type(ListenerHandling::class))
            ->twice();

        $this->events->shouldHaveReceived()
            ->dispatch(m::type(ListenerHandled::class))
            ->twice();
    }

    public function testPropagationStopped(): void
    {
        $this->mockListeners([
            static fn() => false,
            static function () {
                throw new \RuntimeException("This exception shouldn't be thrown because the first listener should stop propagation");
            },
        ]);

        $handlerFactory = new FakeHandlerFactory();
        $processor = new Processor($handlerFactory, $this->events, $this->dispatcher);

        $processor->process($this->message, $this->options());

        self::assertCount(1, $handlerFactory->handlers);
        self::assertTrue($handlerFactory->handlers[0]->fired);
    }

    public function testProcessFail()
    {
        $this->mockListeners([
            static function () {
                throw new FailedException();
            },
        ]);

        $handlerFactory = new FakeHandlerFactory();

        $processor = new Processor($handlerFactory, $this->events, $this->dispatcher);

        self::assertInstanceOf(FailedException::class, $this->catch(fn() => $processor->process($this->message, $this->options())));

        $handler = $handlerFactory->handlers[0];

        self::assertTrue($handler->fired && $handler->failed);
        self::assertFalse($handler->released);

        $this->events->shouldHaveReceived()->dispatch(m::type(ListenerHandling::class))->once();
        $this->events->shouldHaveReceived()->dispatch(m::type(ListenerHandlerExceptionOccurred::class))->once();
        $this->events->shouldHaveReceived()->dispatch(m::type(ListenerHandleFailed::class))->once();
        $this->events->shouldNotHaveReceived('dispatch', [m::type(ListenerHandled::class)]);
    }

    public function testRegularExceptionThenRelease()
    {
        $exceptionMessage = 'Failed handler exception';

        $handler = new FakeHandler($this->message);
        $handler->callback = function () use ($exceptionMessage) {
            throw new \Exception($exceptionMessage);
        };

        $processor = new Processor(new FakeHandlerFactory(), $this->events, $this->dispatcher);

        $exception = $this->catch(fn() => $processor->runHandler($handler, $this->options()));

        self::assertSame($exceptionMessage, $exception?->getMessage());
        self::assertTrue($handler->fired && $handler->released);
        self::assertFalse($handler->failed);

        $this->events->shouldHaveReceived()->dispatch(m::type(ListenerHandling::class))->once();
        $this->events->shouldHaveReceived()->dispatch(m::type(ListenerHandlerExceptionOccurred::class))->once();
        $this->events->shouldNotHaveReceived('dispatch', [m::type(ListenerHandled::class)]);
        $this->events->shouldNotHaveReceived('dispatch', [m::type(ListenerHandleFailed::class)]);
    }

    public function testDonNotReleaseIfLastAttempt()
    {
        $handler = new FakeHandler($this->message);
        $handler->callback = function () {
            throw new \RuntimeException();
        };

        // This attempt is the third
        $handler->attempts = 2;

        $processor = new Processor(new FakeHandlerFactory(), $this->events, $this->dispatcher);

        $options = new ListenerOptions(
            'test-app',
            'rabbitmq',
            ['rabbit.event'],
            maxTries: 3
        );

        self::assertInstanceOf(\RuntimeException::class, $this->catch(fn() => $processor->runHandler($handler, $options)));

        self::assertTrue($handler->fired);
        self::assertTrue($handler->hasFailed());
        self::assertFalse($handler->isReleased());

        $this->events->shouldHaveReceived()->dispatch(m::type(ListenerHandling::class))->once();
        $this->events->shouldHaveReceived()->dispatch(m::type(ListenerHandlerExceptionOccurred::class))->once();
        $this->events->shouldHaveReceived()->dispatch(m::type(ListenerHandleFailed::class))->once();
        $this->events->shouldNotHaveReceived('dispatch', [m::type(ListenerHandled::class)]);
    }

    private function catch(callable $callback): ?\Throwable
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            return $exception;
        }

        return null;
    }

    protected function options(array $overrides = [])
    {
        $options = new ListenerOptions('test-app', 'rabbitmq', ['event.one', 'event.two']);

        foreach ($overrides as $key => $value) {
            $options->{$key} = $value;
        }

        return $options;
    }

    protected function mockListeners(array $listeners)
    {
        $this->dispatcher->shouldReceive('getListeners')
            ->with($this->message->event)
            ->andReturn($listeners);
    }

    public function testProcessSkipsDuplicateMessage()
    {
        $deduplicator = m::mock(\RabbitEvents\Listener\Contracts\Deduplicator::class);
        $deduplicator->shouldReceive('isDuplicate')
            ->with($this->message, 'test-app:event.one,event.two')
            ->once()
            ->andReturn(true);
        $deduplicator->shouldNotReceive('markHandled');

        $processor = new Processor(
            $handlerFactory = new FakeHandlerFactory(),
            $this->events,
            $this->dispatcher,
            $deduplicator
        );

        $processor->process($this->message, $this->options());

        self::assertCount(0, $handlerFactory->handlers);
        $this->events->shouldNotHaveReceived('dispatch');
    }

    public function testProcessMarksMessageHandledOnlyAfterListenersSucceeded()
    {
        $this->mockListeners([static fn() => true]);

        $deduplicator = m::mock(\RabbitEvents\Listener\Contracts\Deduplicator::class);
        $deduplicator->shouldReceive('isDuplicate')->once()->andReturn(false);
        $deduplicator->shouldReceive('markHandled')->with($this->message, 'test-app:event.one,event.two')->once();

        (new Processor(new FakeHandlerFactory(), $this->events, $this->dispatcher, $deduplicator))
            ->process($this->message, $this->options());
    }

    public function testProcessDoesNotMarkMessageHandledWhenListenerThrows()
    {
        $this->mockListeners([static fn() => throw new \RuntimeException('boom')]);

        $deduplicator = m::mock(\RabbitEvents\Listener\Contracts\Deduplicator::class);
        $deduplicator->shouldReceive('isDuplicate')->once()->andReturn(false);
        $deduplicator->shouldNotReceive('markHandled');

        $this->expectException(\RuntimeException::class);

        (new Processor(new FakeHandlerFactory(), $this->events, $this->dispatcher, $deduplicator))
            ->process($this->message, $this->options());
    }

    public function testListenerHandledEventContainsDuration()
    {
        $this->mockListeners([
            static fn() => true,
        ]);

        $processor = new Processor(new FakeHandlerFactory(), $this->events, $this->dispatcher);
        $processor->process($this->message, $this->options());

        $this->events->shouldHaveReceived()->dispatch(m::on(function ($event) {
            return $event instanceof ListenerHandled && is_float($event->duration) && $event->duration >= 0;
        }))->once();
    }

    public function testContextHydrationAndFlush(): void
    {
        if (!class_exists(\Illuminate\Support\Facades\Context::class)) {
            self::markTestSkipped('Context facade not available');
        }

        $app = new \Illuminate\Container\Container();
        $realRepository = new \Illuminate\Log\Context\Repository(new Dispatcher($app));
        $app->instance(\Illuminate\Log\Context\Repository::class, $realRepository);
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);

        try {
            \Illuminate\Support\Facades\Context::add('worker_key', 'worker_val');
            \Illuminate\Support\Facades\Context::addHidden('hidden_key', 'hidden_val');

            $message = new Message('test.event', new Payload(['hello' => 'world']), [
                'message_id' => 'msg-ctx',
                'trace_context' => ['trace_id' => '12345'],
            ]);

            $dispatcher = new RabbitEventsDispatcher($app);
            $observedTraceId = null;
            $observedWorkerKey = null;

            $dispatcher->listen('test.event', function () use (&$observedTraceId, &$observedWorkerKey) {
                $observedTraceId = \Illuminate\Support\Facades\Context::get('trace_id');
                $observedWorkerKey = \Illuminate\Support\Facades\Context::get('worker_key');
            });

            $handlerFactory = new HandlerFactory(
                $app,
                new \RabbitEvents\Listener\Support\Releaser(
                    new \RabbitEvents\Tests\Foundation\Stubs\DestinationStub('test-queue'),
                    new \RabbitEvents\Tests\Foundation\Stubs\ProducerStub()
                )
            );

            $processor = new Processor($handlerFactory, new Dispatcher($app), $dispatcher);
            $processor->process($message, $this->options());

            self::assertSame('12345', $observedTraceId);
            self::assertSame('worker_val', $observedWorkerKey);
            self::assertNull(\Illuminate\Support\Facades\Context::get('trace_id'));
            self::assertSame('worker_val', \Illuminate\Support\Facades\Context::get('worker_key'));
            self::assertSame('hidden_val', \Illuminate\Support\Facades\Context::getHidden('hidden_key'));
        } finally {
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
        }
    }

    public function testProcessSkipsAlreadySucceededListenersAcrossRetriesWithRealComponents(): void
    {
        $app = new \Illuminate\Container\Container();
        $dispatcher = new RabbitEventsDispatcher($app);

        FirstRealListener::$calls = 0;
        SecondFailingRealListener::$calls = 0;

        $dispatcher->listen('order.created', FirstRealListener::class);
        $dispatcher->listen('order.created', SecondFailingRealListener::class);

        $releaseProducer = new \RabbitEvents\Tests\Foundation\Stubs\ProducerStub();
        $releaser = new \RabbitEvents\Listener\Support\Releaser(
            new \RabbitEvents\Tests\Foundation\Stubs\DestinationStub('orders-queue'),
            $releaseProducer
        );
        $handlerFactory = new HandlerFactory($app, $releaser);
        $processor = new Processor($handlerFactory, new Dispatcher($app), $dispatcher);

        $options = new ListenerOptions('test-app', 'rabbitmq', ['order.created'], maxTries: 2, sleep: 0);
        $message = new Message('order.created', new Payload(['order_id' => 99]), ['message_id' => 'msg-retry-1']);

        // Attempt 1 (Consumer::nextMessage increments attempts to 1):
        // FirstRealListener succeeds, SecondFailingRealListener throws -> message is released for retry
        $message->increaseAttempts();
        try {
            $processor->process($message, $options);
            self::fail('Expected RuntimeException on first attempt');
        } catch (\RuntimeException $e) {
            self::assertSame('Second listener boom', $e->getMessage());
        }

        self::assertSame(1, FirstRealListener::$calls);
        self::assertSame(1, SecondFailingRealListener::$calls);
        self::assertCount(1, $releaseProducer->sent, 'Message should have been released back to queue');
        self::assertSame([FirstRealListener::class . '@handle'], $message->getProperty('x-succeeded-listeners'));
        self::assertFalse($message->hasFailed());

        // Attempt 2 (Consumer::nextMessage increments attempts to 2):
        // FirstRealListener is skipped because it already succeeded; SecondFailingRealListener fails permanently
        $message->increaseAttempts();
        try {
            $processor->process($message, $options);
            self::fail('Expected RuntimeException on second attempt');
        } catch (\RuntimeException $e) {
            self::assertSame('Second listener boom', $e->getMessage());
        }

        self::assertSame(1, FirstRealListener::$calls, 'First listener must NOT be re-executed on retry');
        self::assertSame(2, SecondFailingRealListener::$calls);
        self::assertTrue($message->hasFailed(), 'Message must be marked as failed after exhausting maxTries');

        // Verify Failer writes the failing listener class and diagnostic headers
        $dlxProducer = new \RabbitEvents\Tests\Foundation\Stubs\ProducerStub();
        $failer = new \RabbitEvents\Listener\Support\Failer(
            new \RabbitEvents\Tests\Foundation\Stubs\FailedDestinationStub('orders-queue'),
            $dlxProducer
        );
        $failer->send($message);

        self::assertSame(SecondFailingRealListener::class . '@handle', $message->getProperty('x-listener'));
        self::assertSame('Second listener boom', $message->getProperty('x-exception-message'));
        self::assertSame('order.created', $message->getProperty('x-original-routing-key'));
    }

    public function testMethodsOfOneSubscriberAreTrackedSeparately(): void
    {
        $app = new \Illuminate\Container\Container();
        $dispatcher = new RabbitEventsDispatcher($app);
        $dispatcher->listen('order.created', [SubscriberWithTwoMethodsOnOneEvent::class, 'first']);
        $dispatcher->listen('order.created', [SubscriberWithTwoMethodsOnOneEvent::class, 'second']);
        SubscriberWithTwoMethodsOnOneEvent::$calls = [];
        SubscriberWithTwoMethodsOnOneEvent::$failSecond = true;

        $releaser = new \RabbitEvents\Listener\Support\Releaser(
            new \RabbitEvents\Tests\Foundation\Stubs\DestinationStub('orders-queue'),
            new \RabbitEvents\Tests\Foundation\Stubs\ProducerStub()
        );
        $processor = new Processor(new HandlerFactory($app, $releaser), new Dispatcher($app), $dispatcher);
        $options = new ListenerOptions('test-app', 'rabbitmq', ['order.created'], maxTries: 3, sleep: 0);
        $message = (new Message('order.created', new Payload(['order_id' => 1]), ['message_id' => 'm-1']))->increaseAttempts();

        try {
            $processor->process($message, $options);
        } catch (\RuntimeException) {
        }

        // The second method ran on the first delivery although the first one had succeeded.
        self::assertSame(['first', 'second'], SubscriberWithTwoMethodsOnOneEvent::$calls);
        self::assertSame([SubscriberWithTwoMethodsOnOneEvent::class . '@first'], $message->getProperty('x-succeeded-listeners'));

        // On retry only the method that failed runs again.
        SubscriberWithTwoMethodsOnOneEvent::$calls = [];
        SubscriberWithTwoMethodsOnOneEvent::$failSecond = false;
        $processor->process($message->increaseAttempts(), $options);

        self::assertSame(['second'], SubscriberWithTwoMethodsOnOneEvent::$calls);
    }
}

class SubscriberWithTwoMethodsOnOneEvent
{
    public static array $calls = [];
    public static bool $failSecond = false;

    public function first(array $payload = []): void
    {
        self::$calls[] = 'first';
    }

    public function second(array $payload = []): void
    {
        self::$calls[] = 'second';

        if (self::$failSecond) {
            throw new \RuntimeException('Second method boom');
        }
    }
}

class FirstRealListener
{
    public static int $calls = 0;

    public function handle(array $payload = []): void
    {
        self::$calls++;
    }
}

class SecondFailingRealListener
{
    public static int $calls = 0;

    public function handle(array $payload = []): void
    {
        self::$calls++;
        throw new \RuntimeException('Second listener boom');
    }
}

class FakeHandlerFactory extends HandlerFactory
{
    public array $handlers = [];

    public function __construct(public ?Handler $handler = null)
    {
    }

    public function make(Message $message, callable $callback, string $listenerClass): Handler
    {
        if ($this->handler) {
            $this->handlers[] = $this->handler;
            return $this->handler;
        }

        $handler = new FakeHandler($message);
        $handler->callback = $callback;
        $handler->setListenerClass($listenerClass);

        $this->handlers[] = $handler;

        return $handler;
    }
}

class FakeHandler extends Handler
{
    public $listener;
    public bool $fired = false;
    public $callback;
    public $releaseAfter;
    public bool $released = false;
    public $maxTries;
    public $timeoutAt;
    public $attempts = 0;
    public $failedWith;
    public bool $failed = false;
    public $acknowledged = false;
    public $transport;

    public function __construct(Message $message) 
    {
        $this->callback = function(){};
        parent::__construct(
            $message, 
            $this->callback, 
            'FakeListener', 
            m::mock(\RabbitEvents\Listener\Support\Releaser::class)
        );
    }

    public function handle()
    {
        $this->fired = true;
        return call_user_func($this->callback, $this);
    }

    public function __invoke()
    {
        return $this->handle();
    }

    public function setListenerClass(string $listener)
    {
        $this->listenerClass = $listener;

        return $this;
    }
    public function getName(): string
    {
        return 'FakeHandler';
    }

    public function payload(): array
    {
        return [];
    }

    public function maxTries()
    {
        return $this->maxTries;
    }

    public function timeoutAt(): ?int
    {
        return $this->timeoutAt;
    }

    public function release($delay = 0): void
    {
        $this->released = true;
        $this->releaseAfter = $delay;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function countAttempt(): void
    {
        $this->attempts++;
    }

    public function fail($e): void
    {
        $this->markAsFailed();
        $this->failedWith = $e;
    }

    public function __destruct()
    {
        $this->acknowledged = true;
    }
}
