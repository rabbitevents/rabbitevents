<?php

declare(strict_types=1);

namespace RabbitEvents\Publisher;

use Illuminate\Container\Container;
use RabbitEvents\Foundation\Contracts\Transport;

class Publisher
{
    /**
     * @var (callable(): mixed)|null
     */
    protected $transactionManagerResolver;

    public function __construct(
        private MessageFactory $messageFactory,
        private Transport $transport
    ) {
    }

    /**
     * Set the database transaction manager resolver implementation.
     *
     * @param (callable(): mixed)|null $resolver
     * @return $this
     */
    public function setTransactionManagerResolver(?callable $resolver): self
    {
        $this->transactionManagerResolver = $resolver;

        return $this;
    }

    /**
     * Get the database transaction manager: from the resolver if set, else Laravel's `db.transactions`.
     */
    protected function resolveTransactionManager(): mixed
    {
        if ($this->transactionManagerResolver) {
            return ($this->transactionManagerResolver)();
        }

        $container = Container::getInstance();

        return $container->bound('db.transactions') ? $container['db.transactions'] : null;
    }

    /**
     * Determine if the event should be published after the database transaction commits.
     */
    protected function shouldPublishAfterCommit(ShouldPublish $event): bool
    {
        return ($event instanceof ShouldPublishAfterCommit) || ($event->afterCommit ?? false);
    }

    /**
     * Publish event.
     *
     * @param ShouldPublish $event
     * @return string|null
     */
    public function publish(ShouldPublish $event): ?string
    {
        if ($this->shouldPublishAfterCommit($event) && $transactions = $this->resolveTransactionManager()) {
            $publishedId = null;

            $transactions->addCallback(function () use ($event, &$publishedId) {
                $publishedId = $this->executePublish($event);
            });

            return $publishedId;
        }

        return $this->executePublish($event);
    }

    protected function executePublish(ShouldPublish $event): ?string
    {
        $message = $this->messageFactory->make($event);

        $this->transport->send($message);

        return $message->id();
    }
}
