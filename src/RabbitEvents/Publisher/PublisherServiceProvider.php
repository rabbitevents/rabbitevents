<?php

declare(strict_types=1);

namespace RabbitEvents\Publisher;

use Illuminate\Support\ServiceProvider;
use RabbitEvents\Foundation\Serialization\SerializerRegistry;
use RabbitEvents\Foundation\Support\Sender;
use RabbitEvents\Publisher\Console\ObserverMakeCommand;
use RabbitEvents\Foundation\Context;

class PublisherServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->singleton(
            Publisher::class,
            static fn($app) => new Publisher(
                new MessageFactory($app[SerializerRegistry::class]),
                new Sender($app[Context::class]->makeTopic(), $app[Context::class]->createProducer())
            )
        );
    }

    public function register(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([ObserverMakeCommand::class]);
    }
}
