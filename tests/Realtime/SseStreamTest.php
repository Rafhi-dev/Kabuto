<?php

declare(strict_types=1);

namespace Kabuto\Tests\Realtime;

use Kabuto\Realtime\ArrayEventReplayStore;
use Kabuto\Realtime\ArrayRealtimeChannel;
use Kabuto\Realtime\RealtimeAccessDeniedException;
use Kabuto\Realtime\RealtimeManager;
use Kabuto\Realtime\RealtimeMessage;
use Kabuto\Realtime\SseResponse;
use Kabuto\Realtime\SseStream;
use Kabuto\Realtime\SseStreamEnd;
use Kabuto\Tests\TestCase;

final class SseStreamTest extends TestCase
{
    private static function now(float &$clock): callable
    {
        return static function () use (&$clock): float {
            return $clock;
        };
    }

    private static function sleep(float &$clock): callable
    {
        return static function (int $ms) use (&$clock): void {
            $clock += $ms / 1000;
        };
    }

    public function testStreamsLiveMessagesWithServerAssignedIds(): void
    {
        $clock = 0.0;
        $channel = new ArrayRealtimeChannel();
        $channel->publish(new RealtimeMessage('a', [1]));
        $channel->publish(new RealtimeMessage('b', [2]));
        $stream = new SseStream($channel, maxDurationSeconds: 2, pollIntervalMs: 1, now: self::now($clock), sleep: self::sleep($clock));
        $chunks = [];
        $end = $stream->run('ch', null, static function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;
            return true;
        });

        self::assertSame('max-duration', $end->reason);
        self::assertCount(2, $chunks);
        self::assertStringContains('event: a', $chunks[0]);
        self::assertTrue(preg_match('/^id: [0-9a-f]{16}\n/m', $chunks[0]) === 1, 'Live messages must get a fallback id.');
        self::assertStringContains('data: [1]', $chunks[0]);
        self::assertStringContains('event: b', $chunks[1]);
    }

    public function testSendsHeartbeatsWhileIdle(): void
    {
        $clock = 0.0;
        $stream = new SseStream(new ArrayRealtimeChannel(), heartbeatSeconds: 5, maxDurationSeconds: 30, pollIntervalMs: 1, now: self::now($clock), sleep: self::sleep($clock));
        $chunks = [];
        $end = $stream->run('ch', null, static function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;
            return true;
        });

        self::assertSame('max-duration', $end->reason);
        $heartbeats = array_values(array_filter($chunks, static fn (string $c): bool => str_contains($c, ': heartbeat')));
        self::assertCount($end->heartbeats, $heartbeats, 'delivered/heartbeats must mirror the chunks written while idle.');
        self::assertSame($end->heartbeats, $end->delivered);
        self::assertTrue($end->heartbeats >= 3, 'Expected several heartbeats over a 30s idle window, got ' . $end->heartbeats . '.');
    }

    public function testReplaysFromLastEventIdWithoutDuplicates(): void
    {
        $clock = 0.0;
        $channel = new ArrayRealtimeChannel();
        $store = new ArrayEventReplayStore(10, 'inst-1');
        $manager = (new RealtimeManager(['ch' => $channel]))->withReplay($store);
        $manager->publish('ch', new RealtimeMessage('one', null));
        $manager->publish('ch', new RealtimeMessage('two', null));
        $manager->publish('ch', new RealtimeMessage('three', null));

        $stream = new SseStream($channel, replay: $store, maxDurationSeconds: 1, pollIntervalMs: 1, now: self::now($clock), sleep: self::sleep($clock));
        $chunks = [];
        $end = $stream->run('ch', '1-inst-1', static function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;
            return true;
        });

        self::assertSame('max-duration', $end->reason);
        self::assertCount(2, $chunks, 'Replay must deliver cursored messages exactly once.');
        self::assertStringContains('id: 2-inst-1', $chunks[0]);
        self::assertStringContains('event: two', $chunks[0]);
        self::assertStringContains('event: three', $chunks[1]);
    }

    public function testStopsWhenClientDisconnects(): void
    {
        $clock = 0.0;
        $channel = new ArrayRealtimeChannel();
        $channel->publish(new RealtimeMessage('a', null));
        $channel->publish(new RealtimeMessage('b', null));
        $stream = new SseStream($channel, maxDurationSeconds: 10, pollIntervalMs: 1, now: self::now($clock), sleep: self::sleep($clock));
        $seen = 0;
        $end = $stream->run('ch', null, static function (string $chunk) use (&$seen): bool {
            $seen++;
            return $seen < 2;
        });

        self::assertSame('disconnect', $end->reason);
        self::assertSame(1, $end->delivered, 'The failed write attempt is not counted as delivered.');
        self::assertSame(2, $seen);
    }

    public function testEndsOnIdleTimeout(): void
    {
        $clock = 0.0;
        $stream = new SseStream(new ArrayRealtimeChannel(), heartbeatSeconds: 0, idleTimeoutSeconds: 10, pollIntervalMs: 1, now: self::now($clock), sleep: self::sleep($clock));
        $end = $stream->run('ch', null, static fn (string $chunk): bool => true);

        self::assertSame('idle-timeout', $end->reason);
        self::assertTrue($end->seconds >= 10.0);
        self::assertSame(0, $end->delivered);
    }

    public function testStopRequestEndsGracefullyAndStillFlushesMessages(): void
    {
        $clock = 0.0;
        $channel = new ArrayRealtimeChannel();
        $channel->publish(new RealtimeMessage('a', [3]));
        $stream = new SseStream($channel, maxDurationSeconds: 30, pollIntervalMs: 1, now: self::now($clock), sleep: self::sleep($clock));
        $chunks = [];
        $stops = 0;
        $end = $stream->run('ch', null, static function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;
            return true;
        }, shouldStop: static function () use (&$stops): bool {
            $stops++;
            return $stops > 2;
        });

        self::assertSame('stop-requested', $end->reason);
        self::assertStringContains('event: a', $chunks[0]);
        self::assertInstanceOf(SseStreamEnd::class, $end);
    }

    public function testAuthorizationGateRejectsUnauthorizedSubscribers(): void
    {
        $clock = 0.0;
        $stream = new SseStream(new ArrayRealtimeChannel(), heartbeatSeconds: 0, maxDurationSeconds: 1, authorize: static fn (string $channel, mixed $ctx): bool => $ctx === 'admin', pollIntervalMs: 1, now: self::now($clock), sleep: self::sleep($clock));
        self::assertThrows(RealtimeAccessDeniedException::class, static function () use ($stream): void {
            $stream->run('ch', null, static fn (string $chunk): bool => true, 'guest');
        });
        self::assertSame(0, $stream->run('ch', null, static fn (string $chunk): bool => true, 'admin')->delivered);
    }

    public function testDeliversMessagesPublishedDuringStreaming(): void
    {
        $clock = 0.0;
        $channel = new ArrayRealtimeChannel();
        $published = 0;
        $sleep = static function (int $ms) use (&$clock, &$published, $channel): void {
            $clock += $ms / 1000;
            $published++;
            if ($published <= 2) {
                $channel->publish(new RealtimeMessage('live.' . $published, null));
            }
        };
        $stream = new SseStream($channel, maxDurationSeconds: 5, pollIntervalMs: 1, now: self::now($clock), sleep: $sleep);
        $chunks = [];
        $end = $stream->run('ch', null, static function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;
            return true;
        });

        self::assertSame('max-duration', $end->reason);
        self::assertCount(2, $chunks);
        self::assertStringContains('event: live.1', $chunks[0]);
        self::assertStringContains('event: live.2', $chunks[1]);
    }

    public function testHeadersResponseCarriesStreamingDirectives(): void
    {
        $response = SseResponse::headers();
        self::assertSame('text/event-stream; charset=utf-8', $response->headers()['Content-Type']);
        self::assertSame('no', $response->headers()['X-Accel-Buffering']);
        self::assertSame('no-cache', $response->headers()['Cache-Control']);
        self::assertSame('', $response->getBody());
    }
}
