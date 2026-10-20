<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener\HasListeners;

use RabbitEvents\Listener\HasListeners\ListenerDiscoverer;
use RabbitEvents\Tests\Listener\HasListeners\Fixtures\TestListener;
use RabbitEvents\Tests\Listener\TestCase;

class ListenerDiscovererTest extends TestCase
{
    public function testDiscover()
    {
        $path = __DIR__ . '/Fixtures';
        $namespace = 'RabbitEvents\Tests\Listener\HasListeners\Fixtures\\';

        $listeners = ListenerDiscoverer::discover($path, $path, $namespace);

        self::assertContains(TestListener::class, $listeners);
    }

    public function testDiscoverReturnsNothingWhenTheDirectoryDoesNotExist(): void
    {
        // Laravel 11+ applications have no app/Listeners directory by default.
        self::assertSame([], ListenerDiscoverer::discover(__DIR__ . '/missing', __DIR__, 'App\\'));
    }
}
