<?php

declare(strict_types=1);

namespace Kabuto\Tests\Observability;

use Kabuto\Mail\ArrayMailTransport;
use Kabuto\Mail\MailManager;
use Kabuto\Observability\MetricsRegistry;
use Kabuto\Queue\DatabaseQueue;
use Kabuto\Queue\Job;
use Kabuto\Realtime\ArrayRealtimeChannel;
use Kabuto\Realtime\RealtimeManager;
use Kabuto\Realtime\RealtimeMessage;
use Kabuto\Realtime\WebSocketServer;
use Kabuto\Tests\TestCase;
use PDO;

final class MetricsProbeJob implements Job
{
    public function handle(): void {}
}

final class ProviderMetricsTest extends TestCase
{
    public function testDatabaseQueueReportsDepthAndFailedJobs(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $queue = new DatabaseQueue($pdo, 'metrics');
        $queue->dispatch(new MetricsProbeJob());

        $metrics = $queue->metrics();

        self::assertSame(1, $metrics['depth']);
        self::assertSame(0, $metrics['failed']);
        self::assertTrue($queue->health());
    }

    public function testMailArrayTransportHasHealthProbe(): void
    {
        self::assertTrue((new MailManager(new ArrayMailTransport()))->health());
    }

    public function testRealtimeConnectionGaugeTracksAttachedConnections(): void
    {
        $metrics = new MetricsRegistry();
        $server = new WebSocketServer(new RealtimeManager(['updates' => new ArrayRealtimeChannel()]), metrics: $metrics, pingIntervalSeconds: 0, idleTimeoutSeconds: 0);
        $stream = fopen('php://temp', 'w+');
        $server->attach($stream, ['updates']);

        self::assertSame(1, $metrics->snapshot()['gauges']['kabuto_realtime_connections']);
        self::assertSame(1, $server->deliver('updates', new RealtimeMessage('changed', ['id' => 1])));
    }
}
