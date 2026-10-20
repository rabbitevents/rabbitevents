<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Console;

use RabbitEvents\Listener\Dispatcher;

/**
 * The service name and events a listener queue is named after, resolved the same way by
 * rabbitevents:listen and the failed-queue commands.
 */
trait ResolvesListenerQueue
{
    protected function resolveServiceName(): string
    {
        return (string) ($this->option('service') ?: ($this->laravel['config']->get('app.name') ?: 'rabbitevents'));
    }

    /**
     * @param string|null $events Comma-separated events; all registered events if empty
     * @return array<string>
     */
    protected function resolveEvents(?string $events): array
    {
        if ($events) {
            return array_values(array_filter(array_map('trim', explode(',', $events))));
        }

        return $this->laravel->bound(Dispatcher::class) ? $this->laravel[Dispatcher::class]->getEvents() : [];
    }
}
