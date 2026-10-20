<?php

declare(strict_types=1);

namespace Kabuto\Tests\Realtime;

use Kabuto\Realtime\ArrayEventReplayStore;
use Kabuto\Realtime\RealtimeManager;
use Kabuto\Realtime\RealtimeMessage;
use Kabuto\Tests\TestCase;

final class ReplayStoreTest extends TestCase
{
    public function testRegisteredMessagesGetOrderIdAndAreReplayed(): void
    {
        $store = new ArrayEventReplayStore(10, 'inst-1');
        $channel = new \Kabuto\Realtime\ArrayRealtimeChannel();
        $manager = (new RealtimeManager(['ch' => $channel]))->withReplay($store);
        $manager->publish('ch', new RealtimeMessage('one', ['n' => 1]));
        $manager->publish('ch', new RealtimeMessage('two', ['n' => 2]));

        self::assertSame('1-inst-1', $channel->messages()[0]->id);
        self::assertSame('2-inst-1', $channel->messages()[1]->id);

        $replayed = $store->after('ch', '1-inst-1');
        self::assertCount(1, $replayed);
        self::assertSame('two', $replayed[0]->event);
        self::assertSame('2-inst-1', $replayed[0]->id);
    }

    public function testCursorIsolatesChannelsAndRejectsForeignIds(): void
    {
        $store = new ArrayEventReplayStore(10, 'inst-1');
        $a = new \Kabuto\Realtime\ArrayRealtimeChannel();
        $b = new \Kabuto\Realtime\ArrayRealtimeChannel();
        $manager = (new RealtimeManager(['a' => $a, 'b' => $b]))->withReplay($store);
        $manager->publish('a', new RealtimeMessage('a1', null, '1-inst-1'));
        $manager->publish('b', new RealtimeMessage('b1', null, '1-inst-1'));
        $manager->publish('a', new RealtimeMessage('a2', null, '2-inst-1'));

        self::assertCount(2, $store->after('a', '0-inst-1'));
        self::assertCount(1, $store->after('b', '0-inst-1'));
        self::assertCount(0, $store->after('a', 'aa-not-a-sequence'));
        self::assertCount(0, $store->after('a', '99-inst-1'));
    }

    public function testCapacityTrimsOldestMessages(): void
    {
        $store = new ArrayEventReplayStore(2, 'inst-1');
        $channel = new \Kabuto\Realtime\ArrayRealtimeChannel();
        $manager = (new RealtimeManager(['ch' => $channel]))->withReplay($store);
        foreach ([1, 2, 3, 4] as $n) {
            $manager->publish('ch', new RealtimeMessage('e' . $n, null));
        }
        self::assertCount(4, $channel->messages(), 'The live channel keeps every published message.');
        $replayed = $store->after('ch', '0-inst-1');
        self::assertCount(2, $replayed, 'Replay retention respects the configured capacity.');
        self::assertSame('e3', $replayed[0]->event);
        self::assertSame('3-inst-1', $replayed[0]->id);
        self::assertSame('4-inst-1', $replayed[1]->id);
    }

    public function testZeroCapacityDisablesRetention(): void
    {
        $store = new ArrayEventReplayStore(0, 'inst-1');
        $manager = (new RealtimeManager(['ch' => new \Kabuto\Realtime\ArrayRealtimeChannel()]))->withReplay($store);
        $message = new RealtimeMessage('e', null);
        $manager->publish('ch', $message);
        self::assertNull($message->id);
        self::assertCount(0, $store->after('ch', '1-inst-1'));
    }
}
