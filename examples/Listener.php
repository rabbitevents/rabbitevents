<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Http\Middleware\ExampleMiddleware;
use Illuminate\Support\Arr;

class Listener
{
    public array $middleware = [
       ExampleMiddleware::class
    ];

    /**
     * Handle the event.
     * 
     * @param array $payload The payload is an array because the default JSON serializer is used.
     */
    public function handle(array $payload): void
    {
        SomeModel::create(Arr::only($payload, ['item1', 'item2', 'item3']));
    }
}
