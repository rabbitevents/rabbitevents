<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Amqp;

use Enqueue\AmqpTools\DelayStrategy;
use Enqueue\AmqpTools\RabbitMqDlxDelayStrategy;
use Illuminate\Support\Arr;
use Interop\Amqp\AmqpConnectionFactory;
use Interop\Amqp\AmqpContext;
use Interop\Queue\Context;
use Interop\Amqp\Impl\AmqpBind;
use RabbitEvents\Foundation\Contracts\Connection as ConnectionContract;
use RabbitEvents\Foundation\Contracts\Deduplicator;
use RabbitEvents\Foundation\Contracts\Destination;
use RabbitEvents\Foundation\Contracts\Producer;
use RabbitEvents\Foundation\Contracts\QueueConsumer;
use RabbitEvents\Foundation\Deduplication\InMemoryDeduplicator;
use RabbitEvents\Foundation\Deduplication\NullDeduplicator;

class Connection implements ConnectionContract
{
    private array $config;

    /**
     * @var DelayStrategy
     */
    private $delayStrategy;

    /**
     * @var AmqpConnectionFactory
     */
    private $connection;

    /** 
     * @var Context
     */
    private $context;

    private ?Deduplicator $deduplicationStrategy = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return AmqpConnectionFactory
     */
    public function connect(): AmqpConnectionFactory
    {
        if (!$this->connection) {
            $this->connection = $this->factory();
        }

        return $this->connection;
    }

    /**
     * @return AmqpContext
     */
    public function createContext(): Context
    {
        if (!$this->context) {
            $this->context = $this->connect()->createContext();
        }
        
        return $this->context;
    }

    public function createProducer(): Producer
    {
        return new AmqpProducerAdapter($this->createContext()->createProducer());
    }

    public function makeConsumer(Destination $queue): QueueConsumer
    {
        return new AmqpConsumerAdapter($this->createContext()->createConsumer($queue->getOrigin()));
    }

    public function makeTopic(?string $name = null): Destination
    {
        $topic = (new DestinationTopicFactory($this->createContext(), $this))
            ->makeAndDeclare($name ?? $this->getConfig('exchange'));

        return new AmqpDestinationAdapter($topic);
    }

    public function makeQueue(string $queueName, array $events, Destination $topic): Destination
    {
        $queue = (new QueueFactory($this->createContext(), $this))->makeAndDeclare($queueName);

        foreach ($events as $event) {
            $this->createContext()->bind(new AmqpBind($topic->getOrigin(), $queue, $event));
        }

        return new AmqpDestinationAdapter($queue);
    }

    /**
     * @param DelayStrategy $strategy
     * @return $this
     */
    public function setDelayStrategy(DelayStrategy $strategy): self
    {
        $this->delayStrategy = $strategy;

        return $this;
    }

    /**
     * @return DelayStrategy
     */
    public function getDelayStrategy(): DelayStrategy
    {
        if (!$this->delayStrategy) {
            $class = $this->getConfig('delay_strategy', RabbitMqDlxDelayStrategy::class);

            $this->delayStrategy = new $class();
        }

        return $this->delayStrategy;
    }

    public function setDeduplicationStrategy(Deduplicator $strategy): self
    {
        $this->deduplicationStrategy = $strategy;

        return $this;
    }

    public function getDeduplicationStrategy(): Deduplicator
    {
        if (!$this->deduplicationStrategy) {
            if (!$this->getConfig('deduplication.enabled', true)) {
                return $this->deduplicationStrategy = new NullDeduplicator();
            }

            $class = $this->getConfig('deduplication.strategy', InMemoryDeduplicator::class);

            if ($class === InMemoryDeduplicator::class) {
                $cacheSize = (int) $this->getConfig('deduplication.cache_size', 10000);
                $this->deduplicationStrategy = new InMemoryDeduplicator($cacheSize);
            } else {
                $this->deduplicationStrategy = new $class();
            }
        }

        return $this->deduplicationStrategy;
    }

    public function getConfig($key = null, $default = null): mixed
    {
        if (!is_null($key)) {
            return Arr::get($this->config, $key, $default);
        }

        return $this->config;
    }

    /**
     * @return AmqpConnectionFactory
     */
    protected function factory(): AmqpConnectionFactory
    {
        $connectionFactoryClass = $this->getConnectionFactoryClass();

        $factory = new $connectionFactoryClass([
            'dsn' => $this->getConfig('dsn'),
            'host' => $this->getConfig('host', '127.0.0.1'),
            'port' => $this->getConfig('port', 5672),
            'user' => $this->getConfig('user', 'guest'),
            'pass' => $this->getConfig('pass', 'guest'),
            'vhost' => $this->getConfig('vhost', '/'),
            'ssl_on' => $this->getConfig('ssl.is_enabled', false),
            'ssl_verify' => $this->getConfig('ssl.verify_peer', true),
            'ssl_cacert' => $this->getConfig('ssl.cafile'),
            'ssl_cert' => $this->getConfig('ssl.local_cert'),
            'ssl_key' => $this->getConfig('ssl.local_key'),
            'ssl_passphrase' => $this->getConfig('ssl.passphrase'),
            'read_timeout' => $this->getConfig('read_timeout', 3.),
            'write_timeout' => $this->getConfig('write_timeout', 3.),
            'connection_timeout' => $this->getConfig('connection_timeout', 3.),
            'heartbeat' => $this->getConfig('heartbeat', 0),
            'persisted' => $this->getConfig('persisted', false),
            'lazy' => $this->getConfig('lazy', true),
            'qos_global' => $this->getConfig('qos.global', false),
            'qos_prefetch_size' => $this->getConfig('qos.prefetch_size', 0),
            'qos_prefetch_count' => $this->getConfig('qos.prefetch_count', 1),
        ]);

        $factory->setDelayStrategy($this->getDelayStrategy());

        return $factory;
    }

    private function getConnectionFactoryClass(): string
    {
        if (extension_loaded('amqp') && class_exists('Enqueue\AmqpExt\AmqpConnectionFactory')) {
            return \Enqueue\AmqpExt\AmqpConnectionFactory::class;
        } else {
            return \Enqueue\AmqpLib\AmqpConnectionFactory::class;
        }
    }
}
