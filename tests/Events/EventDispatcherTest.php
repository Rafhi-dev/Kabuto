<?php

declare(strict_types=1);

namespace Kabuto\Tests\Events;

use Kabuto\Container\Container;
use Kabuto\Events\Event;
use Kabuto\Events\EventDispatcher;
use Kabuto\Events\Listener;
use Kabuto\Events\QueuedListener;
use Kabuto\Queue\DatabaseQueue;
use Kabuto\Tests\TestCase;
use PDO;

final class EventDispatcherTest extends TestCase
{
    public function testDispatchesSynchronousListener(): void
    {
        SyncListener::$received = 0;
        $container = new Container();
        $dispatcher = new EventDispatcher($container);
        $dispatcher->listen(UserRegistered::class, SyncListener::class);
        self::assertSame(1, $dispatcher->dispatch(new UserRegistered(7)));
        self::assertSame(7, SyncListener::$received);
    }

    public function testDispatchesQueuedListenerToDatabase(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $queue = new DatabaseQueue($pdo);
        $dispatcher = new EventDispatcher(new Container(), static fn (): DatabaseQueue => $queue);
        $dispatcher->listen(UserRegistered::class, QueuedUserListener::class);
        self::assertSame(1, $dispatcher->dispatch(new UserRegistered(8)));
        self::assertTrue($queue->workOnce());
        self::assertSame(8, QueuedUserListener::$received);
    }
}

final readonly class UserRegistered implements Event
{
    public function __construct(public int $userId) {}
}

final class SyncListener implements Listener
{
    public static int $received = 0;
    public function handle(Event $event): void { self::$received = $event->userId; }
}

final class QueuedUserListener implements QueuedListener
{
    public static int $received = 0;
    public function handle(Event $event): void { self::$received = $event->userId; }
}
