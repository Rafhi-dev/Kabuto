<?php

declare(strict_types=1);

namespace Kabuto\Events;

use Kabuto\Application;
use Kabuto\Queue\Job;

final class ListenerJob implements Job
{
    public function __construct(
        private readonly string $listenerClass,
        private readonly Event $event,
    ) {
    }

    public function handle(): void
    {
        $listener = Application::current()->container()->get($this->listenerClass);
        if (!$listener instanceof Listener) {
            throw new \RuntimeException('Event listener must implement ' . Listener::class . '.');
        }
        $listener->handle($this->event);
    }
}
