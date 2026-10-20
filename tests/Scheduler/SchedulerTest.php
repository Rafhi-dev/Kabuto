<?php

declare(strict_types=1);

namespace Kabuto\Tests\Scheduler;

use Kabuto\Scheduler\Scheduler;
use Kabuto\Scheduler\ArrayLockStore;
use Kabuto\Scheduler\ArrayScheduleHistoryStore;
use Kabuto\Scheduler\DatabaseLockStore;
use Kabuto\Scheduler\DatabaseScheduleHistoryStore;
use Kabuto\Scheduler\RedisLockStore;
use Kabuto\Tests\TestCase;
use PDO;
use RuntimeException;

final class SchedulerTest extends TestCase
{
    public function testOnlyDueTasksRunAndRunOncePerInterval(): void
    {
        $runs = 0;
        $scheduler = new Scheduler();
        $scheduler->every(60, function () use (&$runs): void { $runs++; }, 'heartbeat');
        self::assertSame(1, $scheduler->runDue(1000));
        self::assertSame(1, $runs);
        self::assertSame(0, $scheduler->runDue(1030));
        self::assertSame(1, $scheduler->runDue(1060));
        self::assertSame(2, $runs);
    }

    public function testRetriesAndRecordsEachAttempt(): void
    {
        $calls = 0;
        $history = new ArrayScheduleHistoryStore();
        $scheduler = new Scheduler(history: $history);
        $scheduler->every(30, static function () use (&$calls): void {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('retry me');
            }
        }, 'retry-task')->retries(1);

        self::assertSame(1, $scheduler->runDue(300));
        self::assertSame(2, $calls);
        self::assertSame(['failed', 'success'], array_column($history->entries(), 'status'));
        self::assertSame([], $scheduler->failures());
    }

    public function testTerminalFailureIsReportedAndHistoryIsRecorded(): void
    {
        $history = new ArrayScheduleHistoryStore();
        $scheduler = new Scheduler(history: $history);
        $scheduler->every(30, static function (): void { throw new RuntimeException('permanent failure'); }, 'broken-task')
            ->retries(1);

        self::assertSame(0, $scheduler->runDue(300));
        self::assertSame('permanent failure', $scheduler->failures()[0]['error']);
        self::assertCount(2, $history->entries());
        self::assertSame('failed', $history->entries()[1]['status']);
        self::assertSame(2, $history->entries()[1]['attempt']);
    }

    public function testSharedStorePreventsDuplicateServerExecutionWithinSlot(): void
    {
        $locks = new ArrayLockStore();
        $runs = 0;
        $first = new Scheduler($locks);
        $second = new Scheduler($locks);
        $first->every(60, static function () use (&$runs): void { $runs++; }, 'cluster-task', 'cluster-task');
        $second->every(60, static function () use (&$runs): void { $runs++; }, 'cluster-task', 'cluster-task');

        self::assertSame(1, $first->runDue(1200));
        self::assertSame(0, $second->runDue(1200));
        self::assertSame(1, $runs);
    }

    public function testDatabaseLockStoreUsesOwnerAndExpiry(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $locks = new DatabaseLockStore($pdo);
        self::assertTrue($locks->acquire('test-lock', 'owner-a', 60));
        self::assertFalse($locks->acquire('test-lock', 'owner-b', 60));
        self::assertFalse($locks->release('test-lock', 'owner-b'));
        self::assertTrue($locks->release('test-lock', 'owner-a'));
        self::assertTrue($locks->acquire('test-lock', 'owner-b', 60));
    }

    public function testDatabaseLockStoreRecoversExpiredLocks(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $locks = new DatabaseLockStore($pdo);
        $pdo->exec("INSERT INTO kabuto_schedule_locks (lock_key, owner, expires_at) VALUES ('expired', 'old-owner', 1)");
        self::assertTrue($locks->acquire('expired', 'new-owner', 60));
    }

    public function testDatabaseHistoryPersistsAttemptsAndReturnsRecentEntries(): void
    {
        $store = new DatabaseScheduleHistoryStore(new PDO('sqlite::memory:'));
        $store->record(['task_id' => 'daily', 'description' => 'Daily work', 'scheduled_at' => 100, 'attempt' => 1,
            'status' => 'failed', 'started_at' => 100, 'finished_at' => 101, 'duration_ms' => 1000, 'error' => 'temporary']);
        $recent = $store->recent();
        self::assertSame('daily', $recent[0]['task_id']);
        self::assertSame('failed', $recent[0]['status']);
        self::assertSame('temporary', $recent[0]['error']);
    }

    public function testRedisLockStoreUsesAtomicAcquireAndOwnerRelease(): void
    {
        $redis = new class {
            public array $values = [];
            public function set(string $key, string $owner, array $options): bool|string
            {
                if (isset($this->values[$key])) return false;
                $this->values[$key] = $owner;
                return 'OK';
            }
            public function eval(string $script, array $arguments, int $keys): int
            {
                [$key, $owner] = $arguments;
                if (($this->values[$key] ?? null) !== $owner) return 0;
                unset($this->values[$key]);
                return 1;
            }
        };
        $locks = new RedisLockStore($redis, 'test:');
        self::assertTrue($locks->acquire('one', 'owner-a', 60));
        self::assertFalse($locks->acquire('one', 'owner-b', 60));
        self::assertFalse($locks->release('one', 'owner-b'));
        self::assertTrue($locks->release('one', 'owner-a'));
    }

    public function testOverlapLockPreventsReentrantExecutionAcrossSchedulers(): void
    {
        $locks = new ArrayLockStore();
        $runs = 0;
        $second = new Scheduler($locks);
        $first = new Scheduler($locks);
        $second->every(60, static function () use (&$runs): void { $runs++; }, 'overlap-task', 'overlap-task')->onOneServer(false);
        $first->every(60, static function () use (&$runs, $second): void {
            $runs++;
            $second->runDue(1200);
        }, 'overlap-task', 'overlap-task')->onOneServer(false);

        self::assertSame(1, $first->runDue(1200));
        self::assertSame(1, $runs);
    }

    public function testWorkerHonorsGracefulStopRequest(): void
    {
        $stop = false;
        $runs = 0;
        $scheduler = new Scheduler(shutdownGraceSeconds: 1);
        $scheduler->every(60, static function () use (&$runs, &$stop): void { $runs++; $stop = true; }, 'worker-task');
        $processed = $scheduler->runWorker(1, null, static function () use (&$stop): bool { return $stop; });
        self::assertSame(1, $processed);
        self::assertSame(1, $runs);
    }

    public function testMaintenanceModeSkipsTasksUnlessExplicitlyEnabled(): void
    {
        $runs = 0;
        $scheduler = (new Scheduler())->duringMaintenance(static fn (): bool => true);
        $scheduler->every(30, static function () use (&$runs): void { $runs++; }, 'normal-task');
        $scheduler->every(30, static function () use (&$runs): void { $runs++; }, 'maintenance-task')->evenInMaintenance();

        self::assertSame(1, $scheduler->runDue(300));
        self::assertSame(1, $runs);
    }

    public function testTaskIdMustBeUnique(): void
    {
        $scheduler = new Scheduler();
        $scheduler->every(30, static function (): void {}, id: 'duplicate');
        self::assertThrows(\InvalidArgumentException::class, static function () use ($scheduler): void {
            $scheduler->every(30, static function (): void {}, id: 'duplicate');
        });
    }
}
