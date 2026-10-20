<?php declare(strict_types=1);

namespace RabbitEvents\Tests\Listener;

use Illuminate\Support\Arr;
use RabbitEvents\Listener\Dispatcher;
use RabbitEvents\Tests\Listener\Stubs\ListenerStub;
use RabbitEvents\Tests\Listener\Stubs\ListenerStubForMiddleware;

class DispatcherTest extends TestCase
{
    private array $listen = [
        'item.created' => [
            'Listeners/Class1',
            'Listeners/Class2',
        ],
        'item.updated' => [
            'Listeners/Class3'
        ],
        'item.*' => [
            'Listeners/Class4'
        ]
    ];

    public function testGetEvents(): void
    {
        $events = array_keys($this->listen);

        self::assertEquals($events, $this->setupDispatcher()->getEvents());
    }

    public function testListen(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen('item.event', static function() {});

        self::assertTrue($dispatcher->hasListeners('item.event'));
    }

    public function testClosureListenersGetTheEventAndThePayload(): void
    {
        $dispatcher = new Dispatcher();
        $received = [];
        $dispatcher->listen('item.event', function (string $event, array $payload) use (&$received) {
            $received[] = [$event, $payload];
        });

        // Handler calls every listener with (event, payload); closures get both, as in every released version.
        foreach ($dispatcher->getListeners('item.event') as $listener) {
            $listener('item.event', ['a' => 1]);
        }

        self::assertSame([['item.event', ['a' => 1]]], $received);
    }

    public function testSimpleListenerCallWithAssocArrayAsPayload(): void
    {
        $payload = ['item' => true];

        $dispatcher = new Dispatcher();
        $dispatcher->listen('simple', ListenerStub::class);
        $listeners = $dispatcher->getListeners('simple');
        $closure = Arr::first($listeners);

        //array is because listener returns func_get_args
        $this->assertEquals([$payload], $closure('simple', $payload));
    }

    public function testWildcardListenerCallWithAssocArrayAsPayload(): void
    {
        $payload = ['item' => true];

        $dispatcher = new Dispatcher();
        $dispatcher->listen('wildcard.*', ListenerStub::class);
        $listeners = $dispatcher->getListeners('wildcard.*');
        $closure = Arr::first($listeners);

        //array is because listener returns func_get_args
        $this->assertEquals(['wildcard.event', $payload], $closure('wildcard.event', $payload));
    }

    public function testGetListeners()
    {
        $dispatcher = $this->setupDispatcher();

        $preparedListeners = $dispatcher->getListeners('item.created');

        $listener1 = array_shift($preparedListeners);
        self::assertIsCallable($listener1);

        $listener2 = array_shift($preparedListeners);
        self::assertIsCallable($listener2);

        $listener3 = array_shift($preparedListeners);
        self::assertIsCallable($listener3);
    }

    public function testAddListenerWhichIsAnObject()
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen('some.event', new ListenerStubForMiddleware());

        $listeners = $dispatcher->getListeners('some.event');

        $callback = array_shift($listeners);

        $payload = ['pay' => 'load'];
        $result = $callback('some.event', $payload);

        self::assertEquals($payload, array_shift($result));
    }

    public function testListenerCallWithObjectAsPayload(): void
    {
        $payload = new \stdClass();
        $payload->item = true;

        $dispatcher = new Dispatcher();
        $dispatcher->listen('simple', ListenerStub::class);
        $listeners = $dispatcher->getListeners('simple');
        $closure = Arr::first($listeners);
        
        $result = $closure('simple', $payload);
        
        $this->assertEquals([$payload], $result);
    }

    private function setupDispatcher(): Dispatcher
    {
        $dispatcher = new Dispatcher();

        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                $dispatcher->listen($event, $listener);
            }
        }

        return $dispatcher;
    }
}
