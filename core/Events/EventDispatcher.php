<?php

declare(strict_types=1);

namespace Kabuto\Events;

use Closure;
use Kabuto\Container\Container;
use Kabuto\Queue\DatabaseQueue;
use RuntimeException;
use Kabuto\Observability\TraceContext;
use Kabuto\Observability\TraceContextJob;

final class EventDispatcher
{
    /** @var array<class-string<Event>,list<class-string<Listener>>> */
    private array $listeners = [];

    /** @param callable():DatabaseQueue|null $queueResolver */
    public function __construct(
        private readonly Container $container,
        private readonly ?Closure $queueResolver = null,
    ) {
    }

    /** @param class-string<Event> $event @param class-string<Listener> $listener */
    public function listen(string $event, string $listener): self
    {
        if (!is_a($event, Event::class, true)) throw new RuntimeException("{$event} must implement " . Event::class . '.');
        if (!is_a($listener, Listener::class, true)) throw new RuntimeException("{$listener} must implement " . Listener::class . '.');
        $this->listeners[$event][] = $listener;
        return $this;
    }

    public function dispatch(Event $event): int
    {
        $count = 0;
        foreach ($this->listeners[$event::class] ?? [] as $listenerClass) {
            $listener = $this->container->get($listenerClass);
            if ($listener instanceof QueuedListener) {
                if ($this->queueResolver === null) throw new RuntimeException('A database queue resolver is required for queued event listeners.');
                $job = new ListenerJob($listenerClass, $event);
                $context = TraceContext::current();
                ($this->queueResolver)()->dispatch($context === null ? $job : new TraceContextJob($job, $context));
            } else {
                $listener->handle($event);
            }
            $count++;
        }
        return $count;
    }

    /** @return array<class-string<Event>,list<class-string<Listener>>> */
    public function listeners(): array
    {
        return $this->listeners;
    }
}
