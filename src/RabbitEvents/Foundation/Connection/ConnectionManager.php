<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Connection;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use RabbitEvents\Foundation\Context;
use RabbitEvents\Foundation\Contracts\Connection;
use RabbitEvents\Foundation\Contracts\Destination;
use RabbitEvents\Foundation\Contracts\FailedDestination;
use RabbitEvents\Foundation\Contracts\Producer;
use RabbitEvents\Foundation\Contracts\QueueConsumer;
use RabbitEvents\Foundation\Serialization\SerializerRegistry;

class ConnectionManager implements Connection
{
    /**
     * The active connection instances.
     *
     * @var array<string, Connection>
     */
    protected array $connections = [];

    public function __construct(
        protected Container $app,
        protected ConnectionFactory $factory
    ) {
    }

    /**
     * Get a connection instance.
     *
     * @param string|null $name
     * @return Connection
     *
     * @throws InvalidArgumentException
     */
    public function connection(?string $name = null): Connection
    {
        $name = $name ?: $this->getDefaultConnection();

        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->resolve($name);
        }

        return $this->connections[$name];
    }

    /**
     * Resolve the given connection by name.
     *
     * @param string $name
     * @return Connection
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): Connection
    {
        $config = $this->configuration($name);

        return $this->factory->make($config);
    }

    /**
     * Get the configuration for a connection.
     *
     * @param string $name
     * @return array
     *
     * @throws InvalidArgumentException
     */
    public function configuration(string $name): array
    {
        $config = $this->app['config']['rabbitevents'] ?? [];
        $connections = Arr::get($config, 'connections', []);

        if (!isset($connections[$name])) {
            throw new InvalidArgumentException("RabbitEvents connection [{$name}] is not defined.");
        }

        return $connections[$name];
    }

    /**
     * Get a Context instance for the given connection.
     *
     * @param string|null $connectionName
     * @return Context
     */
    public function context(?string $connectionName = null): Context
    {
        return new Context(
            $this->connection($connectionName),
            $this->app->make(SerializerRegistry::class)
        );
    }

    /**
     * The default connection name.
     *
     * @var string|null
     */
    protected ?string $defaultConnection = null;

    /**
     * Get the default connection name.
     *
     * @return string
     */
    public function getDefaultConnection(): string
    {
        if ($this->defaultConnection !== null) {
            return $this->defaultConnection;
        }

        $config = $this->app['config']['rabbitevents'] ?? [];

        return Arr::get($config, 'default', 'rabbitmq');
    }

    /**
     * Set the default connection name.
     *
     * @param string $name
     * @return void
     */
    public function setDefaultConnection(string $name): void
    {
        $this->defaultConnection = $name;
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param string $driver
     * @param Closure $callback
     * @return $this
     */
    public function extend(string $driver, Closure $callback): self
    {
        $this->factory->extend($driver, $callback);

        return $this;
    }

    /**
     * Disconnect from the given connection and remove from local cache.
     *
     * @param string|null $name
     * @return void
     */
    public function purge(?string $name = null): void
    {
        $name = $name ?: $this->getDefaultConnection();

        unset($this->connections[$name]);
    }

    public function createProducer(): Producer
    {
        return $this->connection()->createProducer();
    }

    public function makeConsumer(Destination $queue): QueueConsumer
    {
        return $this->connection()->makeConsumer($queue);
    }

    public function makeTopic(): Destination
    {
        return $this->connection()->makeTopic();
    }

    public function makeQueue(string $queueName, array $events, Destination $topic): Destination
    {
        return $this->connection()->makeQueue($queueName, $events, $topic);
    }

    public function makeFailedQueue(string $queueName): FailedDestination
    {
        return $this->connection()->makeFailedQueue($queueName);
    }

    /**
     * Dynamically pass methods to the default connection.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        return call_user_func_array([$this->connection(), $method], $parameters);
    }
}
