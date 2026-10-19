<?php

declare(strict_types=1);

namespace RabbitEvents\Listener;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use RabbitEvents\Listener\Facades\RabbitEvents;

class ListenerServiceProvider extends BaseServiceProvider
{
    use HasListeners\RegisterListeners;

    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected array $listen = [];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Console\ListenCommand::class,
            Console\EventsListCommand::class,
            Console\EventsCacheCommand::class,
            Console\EventsClearCommand::class,
            Console\FailedCommand::class,
            Console\RetryCommand::class,
            Console\ForgetCommand::class,
            Console\FlushFailedCommand::class,
        ]);

        foreach ($this->listens() as $event => $listeners) {
            foreach ($listeners as $listener) {
                RabbitEvents::listen($event, $listener);
            }
        }
    }

    /**
     * Get the events and handlers.
     *
     * @return array
     */
    public function listens(): array
    {
        if ($this->eventsAreCached()) {
            $listeners = array_merge_recursive(
                $this->listen,
                require $this->app->bootstrapPath('cache/rabbitevents.php')
            );
        } else {
            if ($this->shouldDiscoverEvents()) {
                $this->listenerClasses = array_unique(array_merge(
                    $this->listenerClasses,
                    $this->discoverEvents()
                ));
            }

            $listeners = array_merge_recursive($this->listen, $this->getEventsFromAttributes());
        }

        return $this->deduplicateListeners($listeners);
    }

    /**
     * Determine if the application events are cached.
     *
     * @return bool
     */
    protected function eventsAreCached(): bool
    {
        return $this->app->bound('path.bootstrap') &&
            file_exists($this->app->bootstrapPath('cache/rabbitevents.php'));
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents(): bool
    {
        return true;
    }

    /**
     * Get the listener directory path.
     *
     * @return string
     */
    protected function listenerDirectory(): string
    {
        return $this->app->path('Listeners');
    }

    /**
     * Discover the events and listeners for the application.
     *
     * @return array
     */
    public function discoverEvents(): array
    {
        return HasListeners\ListenerDiscoverer::discover(
            $this->listenerDirectory(),
            $this->app->path(),
            $this->app->getNamespace()
        );
    }

    public function register(): void
    {
        $this->app->singleton(Dispatcher::class);
        $this->app->alias(Dispatcher::class, 'rabbitevents.events');
        // Deduplication needs a store shared by the workers; the rabbitevents/deduplication package provides one.
        $this->app->bindIf(Contracts\Deduplicator::class, Deduplication\NullDeduplicator::class);
        $this->registerPublishing();
    }

    /**
     * Set up the resource publishing groups for RabbitEvents.
     *
     * @return void
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $serviceProvider = 'RabbitEventsServiceProvider';

            $this->publishes([
                __DIR__ . "/stubs/$serviceProvider.stub" => $this->app->path("Providers/$serviceProvider.php"),
            ], 'rabbitevents-listener-provider');
        }
    }

}
