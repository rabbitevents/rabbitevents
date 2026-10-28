<?php

declare(strict_types=1);

namespace RabbitEvents\Publisher;

use Illuminate\Support\ServiceProvider;
use RabbitEvents\Foundation\Serialization\SerializerRegistry;
use RabbitEvents\Foundation\Support\Sender;
use RabbitEvents\Publisher\Console\ObserverMakeCommand;
use RabbitEvents\Publisher\Contracts\Outbox;
use RabbitEvents\Publisher\Outbox\NullOutbox;
use RabbitEvents\Foundation\Context;

class PublisherServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // An outbox package binds its own implementation.
        $this->app->bindIf(Outbox::class, NullOutbox::class);

        $this->app->singleton(
            Publisher::class,
            static fn($app) => new Publisher(
                new MessageFactory($app[SerializerRegistry::class]),
                new Sender($app[Context::class]->makeTopic(), $app[Context::class]->createProducer()),
                $app[Outbox::class],
                $app->bound('events') ? $app['events'] : null
            )
        );

        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([ObserverMakeCommand::class]);
    }
}
