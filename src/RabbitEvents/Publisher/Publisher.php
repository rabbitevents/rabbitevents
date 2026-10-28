<?php

declare(strict_types=1);

namespace RabbitEvents\Publisher;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use RabbitEvents\Foundation\Contracts\Transport;
use RabbitEvents\Publisher\Contracts\Outbox;
use RabbitEvents\Publisher\Events\MessagePublished;

class Publisher
{
    /**
     * @var (callable(): mixed)|null
     */
    protected $transactionManagerResolver;

    public function __construct(
        private MessageFactory $messageFactory,
        private Transport $transport,
        private ?Outbox $outbox = null,
        private ?Dispatcher $events = null
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

    /**
     * Send the message, or give it to the outbox, which sends it itself.
     */
    protected function executePublish(ShouldPublish $event): ?string
    {
        $message = $this->messageFactory->make($event);

        if (!$this->outbox?->store($event, $message)) {
            $this->transport->send($message);
            $this->events?->dispatch(new MessagePublished($message));
        }

        return $message->id();
    }
}
