<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener\Console;

use ArrayAccess;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as LaravelDispatcher;
use Mockery as m;
use RabbitEvents\Foundation\Context;
use RabbitEvents\Foundation\Contracts\Destination;
use RabbitEvents\Foundation\Contracts\Producer;
use RabbitEvents\Foundation\Contracts\QueueConsumer;
use RabbitEvents\Listener\Console\ListenCommand;
use RabbitEvents\Listener\Dispatcher;
use RabbitEvents\Listener\Support\KillSwitch;
use RabbitEvents\Listener\Worker;
use RabbitEvents\Listener\WorkerExitStatus;
use RabbitEvents\Tests\Listener\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ListenCommandTest extends TestCase
{
    public function test_aborts_when_no_listeners_registered_and_no_arguments_passed(): void
    {
        $app = $this->createMockApplication();
        $dispatcher = new Dispatcher($app);
        $app->instance(Dispatcher::class, $dispatcher);

        $command = new ListenCommand();
        $command->setLaravel($app);

        $input = new ArrayInput([]);
        $bufferedOutput = new BufferedOutput();
        $output = new OutputStyle($input, $bufferedOutput);

        $input->bind($command->getDefinition());
        $command->setInput($input);
        $command->setOutput($output);

        $context = m::mock(Context::class);
        $worker = m::mock(Worker::class);

        $result = $command->handle($context, $worker);

        self::assertSame(WorkerExitStatus::ERROR->value, $result);
        self::assertStringContainsString('No RabbitEvents listeners registered in the application', $bufferedOutput->fetch());
    }

    public function test_aborts_when_non_existent_event_argument_provided(): void
    {
        $app = $this->createMockApplication();
        $dispatcher = new Dispatcher($app);
        $dispatcher->listen('user.registered', static function () {});
        $app->instance(Dispatcher::class, $dispatcher);

        $command = new ListenCommand();
        $command->setLaravel($app);

        $input = new ArrayInput(['events' => 'non.existent.event']);
        $bufferedOutput = new BufferedOutput();
        $output = new OutputStyle($input, $bufferedOutput);

        $input->bind($command->getDefinition());
        $command->setInput($input);
        $command->setOutput($output);

        $context = m::mock(Context::class);
        $worker = m::mock(Worker::class);

        $result = $command->handle($context, $worker);

        self::assertSame(WorkerExitStatus::ERROR->value, $result);
        $outputContent = $bufferedOutput->fetch();
        self::assertStringContainsString('No listeners registered for event(s): non.existent.event', $outputContent);
        self::assertStringContainsString('Available registered events: user.registered', $outputContent);
    }

    public function test_warns_on_partial_missing_events_but_proceeds(): void
    {
        $app = $this->createMockApplication();
        $dispatcher = new Dispatcher($app);
        $dispatcher->listen('user.registered', static function () {});
        $app->instance(Dispatcher::class, $dispatcher);

        $command = new ListenCommand();
        $command->setLaravel($app);

        $input = new ArrayInput(['events' => 'user.registered,missing.event']);
        $bufferedOutput = new BufferedOutput();
        $output = new OutputStyle($input, $bufferedOutput);

        $input->bind($command->getDefinition());
        $command->setInput($input);
        $command->setOutput($output);

        $context = m::mock(Context::class);
        $worker = m::mock(Worker::class);
        $destination = m::mock(Destination::class);
        $consumer = m::mock(\RabbitEvents\Foundation\Consumer::class);
        $producer = m::mock(Producer::class);

        $context->shouldReceive('makeTopic')->andReturn($destination);
        $context->shouldReceive('makeQueue')->once()->andReturn($destination);
        $context->shouldReceive('makeFailedQueue')->once()->andReturn(new \RabbitEvents\Tests\Foundation\Stubs\FailedDestinationStub('q'));
        $context->shouldReceive('createProducer')->andReturn($producer);
        $context->shouldReceive('makeConsumer')->once()->andReturn($consumer);

        $worker->shouldReceive('setReleaser')->once();
        $worker->shouldReceive('setFailer')->once();
        $worker->shouldReceive('work')->once()->andReturn(WorkerExitStatus::SUCCESS);

        $result = $command->handle($context, $worker);

        self::assertSame(WorkerExitStatus::SUCCESS->value, $result);
        $outputContent = $bufferedOutput->fetch();
        self::assertStringContainsString('No listeners registered for event(s): missing.event', $outputContent);
    }

    public function test_matches_wildcard_registered_listeners(): void
    {
        $app = $this->createMockApplication();
        $dispatcher = new Dispatcher($app);
        $dispatcher->listen('user.*', static function () {});
        $app->instance(Dispatcher::class, $dispatcher);

        $command = new ListenCommand();
        $command->setLaravel($app);

        $input = new ArrayInput(['events' => 'user.created']);
        $bufferedOutput = new BufferedOutput();
        $output = new OutputStyle($input, $bufferedOutput);

        $input->bind($command->getDefinition());
        $command->setInput($input);
        $command->setOutput($output);

        $context = m::mock(Context::class);
        $worker = m::mock(Worker::class);
        $destination = m::mock(Destination::class);
        $consumer = m::mock(\RabbitEvents\Foundation\Consumer::class);
        $producer = m::mock(Producer::class);

        $context->shouldReceive('makeTopic')->andReturn($destination);
        $context->shouldReceive('makeQueue')->once()->andReturn($destination);
        $context->shouldReceive('makeFailedQueue')->once()->with('test-service:user.created')->andReturn(new \RabbitEvents\Tests\Foundation\Stubs\FailedDestinationStub('test-service:user.created'));
        $context->shouldReceive('createProducer')->andReturn($producer);
        $context->shouldReceive('makeConsumer')->once()->andReturn($consumer);

        $worker->shouldReceive('setReleaser')->once();
        $worker->shouldReceive('setFailer')->once();
        $worker->shouldReceive('work')->once()->andReturn(WorkerExitStatus::SUCCESS);

        $result = $command->handle($context, $worker);

        self::assertSame(WorkerExitStatus::SUCCESS->value, $result);
        self::assertStringNotContainsString('No listeners registered', $bufferedOutput->fetch());
    }

    public function test_proceeds_for_an_event_a_wildcard_listener_receives_across_several_words(): void
    {
        $app = $this->createMockApplication();
        $dispatcher = new Dispatcher($app);
        // Dispatcher wildcards match like Str::is(): "order.*" receives "order.item.created".
        $dispatcher->listen('order.*', static function () {});
        $app->instance(Dispatcher::class, $dispatcher);

        [$result, $output, $context] = $this->runListen($app, ['events' => 'order.item.created']);

        self::assertSame(WorkerExitStatus::SUCCESS->value, $result);
        self::assertStringNotContainsString('No listeners registered', $output);
    }

    public function test_falls_back_to_a_service_name_and_trims_events(): void
    {
        $app = $this->createMockApplication(['app.name' => null]);
        $dispatcher = new Dispatcher($app);
        $dispatcher->listen('user.created', static function () {});
        $dispatcher->listen('user.updated', static function () {});
        $app->instance(Dispatcher::class, $dispatcher);

        [$result, , $context] = $this->runListen($app, ['events' => ' user.created , user.updated ']);

        self::assertSame(WorkerExitStatus::SUCCESS->value, $result);
        $context->shouldHaveReceived('makeQueue')->with('rabbitevents:user.created,user.updated', ['user.created', 'user.updated'], m::any());
    }

    public function test_kill_switch_is_disabled_by_default(): void
    {
        [, , , $worker] = $this->runListen($this->appListening('user.created'), ['events' => 'user.created']);

        $worker->shouldNotHaveReceived('setKillSwitch');
    }

    public function test_kill_switch_is_configured_from_config(): void
    {
        $app = $this->appListening('user.created', [
            'rabbitevents.kill_switch.enabled' => true,
            // env() values are strings
            'rabbitevents.kill_switch.activation_threshold' => '5',
            'rabbitevents.kill_switch.trip_threshold' => '50',
            'rabbitevents.kill_switch.tracking_period' => '30',
            'rabbitevents.kill_switch.restart_timeout' => '120',
        ]);

        [, , , $worker] = $this->runListen($app, ['events' => 'user.created']);

        $worker->shouldHaveReceived('setKillSwitch')->with(m::on(
            fn(KillSwitch $switch) => $switch->activationThreshold === 5
                && $switch->tripThreshold === 50
                && $switch->trackingPeriod === 30
                && $switch->restartTimeout === 120
        ))->once();
    }

    private function appListening(string $event, array $config = []): Container
    {
        $app = $this->createMockApplication($config);
        $dispatcher = new Dispatcher($app);
        $dispatcher->listen($event, static function () {});
        $app->instance(Dispatcher::class, $dispatcher);

        return $app;
    }

    /**
     * @return array{int, string, Context&m\MockInterface, Worker&m\MockInterface}
     */
    private function runListen(Container $app, array $input): array
    {
        $command = new ListenCommand();
        $command->setLaravel($app);
        $arrayInput = new ArrayInput($input);
        $bufferedOutput = new BufferedOutput();
        $arrayInput->bind($command->getDefinition());
        $command->setInput($arrayInput);
        $command->setOutput(new OutputStyle($arrayInput, $bufferedOutput));

        $destination = m::mock(Destination::class);
        $context = m::spy(Context::class);
        $context->shouldReceive('makeTopic')->andReturn($destination);
        $context->shouldReceive('makeQueue')->andReturn($destination);
        $context->shouldReceive('makeFailedQueue')->andReturnUsing(fn($queue) => new \RabbitEvents\Tests\Foundation\Stubs\FailedDestinationStub($queue));
        $context->shouldReceive('createProducer')->andReturn(m::mock(Producer::class));
        $context->shouldReceive('makeConsumer')->andReturn(m::mock(\RabbitEvents\Foundation\Consumer::class));

        $worker = m::spy(Worker::class);
        $worker->shouldReceive('work')->andReturn(WorkerExitStatus::SUCCESS);

        $result = $command->handle($context, $worker);

        return [$result, $bufferedOutput->fetch(), $context, $worker];
    }

    private function createMockApplication(array $config = []): Container
    {
        $app = new Container();
        $app->bind(\RabbitEvents\Listener\Contracts\Deduplicator::class, \RabbitEvents\Listener\Deduplication\NullDeduplicator::class);
        $configObj = new class(array_merge([
            'app.name' => 'test-service',
            'rabbitevents.default' => 'rabbitmq',
            'rabbitevents.logging.enabled' => false,
        ], $config)) implements ArrayAccess {
            /** @param array<string, mixed> $items */
            public function __construct(private array $items) {}
            public function offsetExists($offset): bool { return isset($this->items[$offset]); }
            public function offsetGet($offset): mixed { return $this->items[$offset] ?? null; }
            public function offsetSet($offset, $value): void { $this->items[$offset] = $value; }
            public function offsetUnset($offset): void { unset($this->items[$offset]); }
            public function get(string $key, mixed $default = null): mixed { return $this->items[$key] ?? $default; }
        };

        $app->instance('config', $configObj);
        $app->instance('events', new LaravelDispatcher($app));

        return $app;
    }
}
