<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\HasListeners;

use RabbitEvents\Listener\Dispatcher;

trait RegisterListeners
{
    /**
     * The list of listener classes that should be auto-discovered.
     *
     * @var array
     */
    protected array $listenerClasses = [];

    /**
     * Get the events and handlers from the attribute listeners.
     * 
     * @return array
     */
    public function getEventsFromAttributes(): array
    {
        $listeners = [];

        foreach ($this->listenerClasses as $listenerClass) {
            $listeners = array_merge_recursive($listeners, $this->resolveListenerClass($listenerClass));
        }

        return $listeners;
    }

    protected function resolveListenerClass(string $listenerClass): array
    {
        return ListenerDiscoverer::eventsFromClass($listenerClass);
    }

    protected function deduplicateListeners(array $listeners): array
    {
        $deduplicated = [];

        foreach ($listeners as $event => $eventListeners) {
            $seenKeys = [];

            foreach ($eventListeners as $listener) {
                $key = Dispatcher::listenerKey($listener);

                if (!isset($seenKeys[$key])) {
                    $seenKeys[$key] = count($deduplicated[$event] ?? []);
                    $deduplicated[$event][] = $listener;
                } else {
                    $existingIndex = $seenKeys[$key];
                    $existingListener = $deduplicated[$event][$existingIndex];

                    if (is_array($listener) && is_string($existingListener)) {
                        $deduplicated[$event][$existingIndex] = $listener;
                    }
                }
            }
        }

        return $deduplicated;
    }
}
