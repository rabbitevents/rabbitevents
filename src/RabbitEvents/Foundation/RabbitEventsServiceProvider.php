<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation;

use Illuminate\Support\ServiceProvider;
use RabbitEvents\Foundation\Connection\ConnectionFactory;
use RabbitEvents\Foundation\Connection\ConnectionManager;
use RabbitEvents\Foundation\Console\InstallCommand;
use RabbitEvents\Foundation\Serialization\JsonSerializer;

class RabbitEventsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Defaults for keys missing from a config/rabbitevents.php published by an older version.
        $this->mergeConfigFrom(__DIR__ . '/config/rabbitevents.php', 'rabbitevents');

        $this->app->singleton(Serialization\SerializerRegistry::class, function ($app) {
            $registry = new Serialization\SerializerRegistry();
            $registry->register(
                $app->make($app['config']['rabbitevents.default_serializer'] ?? JsonSerializer::class), 
                true
            );

            return $registry;
        });

        $this->app->singleton(ConnectionFactory::class, fn($app) => new ConnectionFactory($app));

        $this->app->singleton(
            ConnectionManager::class,
            fn($app) => new ConnectionManager($app, $app[ConnectionFactory::class])
        );

        $this->app->singleton(Contracts\Connection::class, static fn($app) => $app[ConnectionManager::class]->connection());

        $this->app->singleton(
            Context::class,
            static fn($app) => new Context(
                $app[Contracts\Connection::class],
                $app[Serialization\SerializerRegistry::class]
            )
        );

        $this->registerCommands();
        $this->registerPublishing();
    }

    /**
     * Register RabbitEvent's publishing.
     *
     * @return void
     */
    protected function registerPublishing()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/rabbitevents.php' => $this->app->configPath('rabbitevents.php'),
            ], 'rabbitevents-config');
        }
    }

    protected function registerCommands()
    {
        $this->commands([
            InstallCommand::class,
        ]);
    }
}
