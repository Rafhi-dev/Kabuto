<?php

declare(strict_types=1);

namespace Kabuto\Tests\Queue;

use Kabuto\Queue\DatabaseQueue;
use Kabuto\Queue\Job;
use Kabuto\Tests\TestCase;
use PDO;

final class DatabaseQueueTest extends TestCase
{
    private DatabaseQueue $queue;
    private string $marker;

    protected function setUp(): void
    {
        $this->queue = new DatabaseQueue(new PDO('sqlite::memory:'));
        $this->marker = sys_get_temp_dir() . '/kabuto-job-' . uniqid();
    }

    public function testDispatchAndWorkOnceExecutesJob(): void
    {
        $this->queue->dispatch(new WriteJob($this->marker));
        self::assertTrue($this->queue->workOnce());
        self::assertSame('done', file_get_contents($this->marker));
        self::assertCount(0, $this->queue->failed());
    }

    public function testFailedJobIsRetriedThenMarkedFailed(): void
    {
        $this->queue->dispatch(new FailingJob());
        self::assertTrue($this->queue->workOnce(2, 0));
        self::assertTrue($this->queue->workOnce(2, 0));
        $failed = $this->queue->failed();
        self::assertCount(1, $failed);
        self::assertSame(2, (int) $failed[0]['attempts']);
        $this->queue->retry((int) $failed[0]['id']);
        self::assertCount(0, $this->queue->failed());
    }

    protected function tearDown(): void { @unlink($this->marker); }
}

final class WriteJob implements Job
{
    public function __construct(private readonly string $path) {}
    public function handle(): void { file_put_contents($this->path, 'done'); }
}

final class FailingJob implements Job
{
    public function handle(): void { throw new \RuntimeException('job failed'); }
}
