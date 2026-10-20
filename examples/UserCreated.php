<?php
declare(strict_types=1);

namespace App\RabbitEvents;

use RabbitEvents\Publisher\Support\AbstractPublishableEvent;

class UserCreated extends AbstractPublishableEvent
{
    public function __construct(public readonly User $user)
    {
    }

    public function publishEventKey(): string
    {
        return 'user.created';
    }

    public function toPublish(): array
    {
        return $this->user->toArray();
    }
}

$user = User::create(\Request::only(['first_name', 'last_name']));

// 1. use `Publishable` trait
UserCreated::publish($user);

// 2. Use helper function `publish` with event that implements `ShouldPublish`
$event = new UserCreated($user);

publish($event);

// 3. Old way. Just use `publish` helper function

publish('user.created', [$user->toArray()]);
