<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Collision;

use FilesystemIterator;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use StepDispatcher\Models\Step;
use StepDispatcher\StepDispatcherServiceProvider;

/**
 * Base for tests where separate PHP processes contend on one real database
 * server, MySQL unless CollisionEnvironment says otherwise. Unlike the Feature
 * suite it uses no wrapping transaction and no frozen clock, because other
 * processes must see committed rows.
 *
 * Every test rebuilds the schema from the package migrations on the fixed
 * collision database. If the server cannot be reached the test is skipped with the
 * reason shown, never passed silently.
 */
abstract class CollisionTestCase extends BaseTestCase
{
    protected string $workDirectory;

    private const WORK_DIRECTORY_PREFIX = 'step-dispatcher-collision-';

    protected function getPackageProviders($app): array
    {
        return [StepDispatcherServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->workDirectory = $this->makeWorkDirectory();

        CollisionEnvironment::configure($app, $this->workDirectory);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $reason = CollisionEnvironment::unreachableReason();

        if ($reason !== null) {
            $this->markTestSkipped(sprintf(
                'Collision tests need '.CollisionEnvironment::driver().' at %s:%d, which is not reachable (%s). Start the server and rerun tests/Collision.',
                CollisionEnvironment::host(),
                CollisionEnvironment::port(),
                $reason,
            ));
        }

        CollisionEnvironment::ensureDatabaseExists();
        $this->rebuildSchema();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeWorkDirectory();
    }

    /**
     * Drops and recreates every table on the collision database from the
     * package migrations, then adds the table the fixture job writes to.
     */
    protected function rebuildSchema(): void
    {
        $connection = DB::connection(CollisionEnvironment::CONNECTION);

        CollisionEnvironment::assertConnectedToCollisionDatabase($connection);

        $exit = Artisan::call('migrate:fresh', [
            '--database' => CollisionEnvironment::CONNECTION,
            '--force' => true,
        ]);

        if ($exit !== 0) {
            throw new RuntimeException("migrate:fresh failed with exit code {$exit} on the collision database.");
        }

        Schema::connection(CollisionEnvironment::CONNECTION)->create('collision_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('step_id')->index();
            $table->unsignedInteger('pid');
            $table->timestamp('recorded_at', 6);
        });
    }

    /**
     * @param  list<array{scenario: string, arguments: array<string, mixed>}>  $workers
     */
    protected function launchWorkers(array $workers): CollisionWorkerBatch
    {
        return CollisionWorkerBatch::launch($workers, $this->workDirectory);
    }

    /**
     * Runs one round to completion and returns the payloads. Every worker is
     * terminated in the finally block, even when the round fails.
     *
     * @param  list<array{scenario: string, arguments: array<string, mixed>}>  $workers
     * @return list<array<string, mixed>>
     */
    protected function runWorkers(array $workers, string $context): array
    {
        $batch = $this->launchWorkers($workers);

        try {
            return $this->assertWorkersFinished($batch->wait(), $context);
        } finally {
            $batch->terminate();
        }
    }

    /**
     * Forces one exact order of events on every run. This test holds an
     * uncommitted change to a step, starts one worker, and commits only once
     * that worker is waiting on the row. A worker that decided from an earlier
     * read of the row then acts on out-of-date state; one that reads under the
     * row lock sees the change.
     *
     * @param  array<string, mixed>  $change  columns of the uncommitted change
     * @param  array{scenario: string, arguments: array<string, mixed>}  $worker
     * @return array<string, mixed> the worker's payload
     */
    protected function runWorkerBehindUncommittedChange(int $stepId, array $change, array $worker, string $context): array
    {
        $batch = null;

        try {
            return $this->holdingRows(
                static function ($connection) use ($stepId, $change): void {
                    $connection->table(Step::tableName())->where('id', $stepId)->update($change);
                },
                function () use ($worker, $context, &$batch): void {
                    $batch = $this->launchWorkers([$worker]);

                    $this->waitForWorkerBehindRowLock($context);
                },
                function () use (&$batch, $context): array {
                    return $this->assertWorkersFinished($batch->wait(), $context)[0];
                },
            );
        } finally {
            $batch?->terminate();
        }
    }

    /**
     * Runs $hold inside a transaction, then $whileHeld with those rows still
     * uncommitted, then commits and returns what $afterCommit returns.
     *
     * @template TResult
     *
     * @param  callable(Connection): void  $hold
     * @param  callable(): void  $whileHeld
     * @param  callable(): TResult  $afterCommit
     * @return TResult
     */
    protected function holdingRows(callable $hold, callable $whileHeld, callable $afterCommit): mixed
    {
        $connection = DB::connection(CollisionEnvironment::CONNECTION);

        $connection->beginTransaction();

        try {
            $hold($connection);
            $whileHeld();

            $connection->commit();

            return $afterCommit();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }

    /**
     * Waits until a session on the collision database is blocked on a row lock.
     * Sessions on any other database are ignored.
     */
    protected function waitForWorkerBehindRowLock(string $context): void
    {
        $deadline = microtime(true) + 15;

        do {
            // Postgres keeps one copy of this view for the whole transaction,
            // and the caller is inside the transaction holding the row.
            if (CollisionEnvironment::usesPostgres()) {
                DB::connection(CollisionEnvironment::CONNECTION)->select('SELECT pg_stat_clear_snapshot()');
            }

            $waiting = DB::connection(CollisionEnvironment::CONNECTION)->selectOne(
                CollisionEnvironment::usesPostgres()
                    ? "SELECT COUNT(*) AS total FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND datname = ?"
                    : "SELECT COUNT(*) AS total
                         FROM information_schema.innodb_trx AS trx
                         JOIN information_schema.processlist AS session ON session.id = trx.trx_mysql_thread_id
                        WHERE trx.trx_state = 'LOCK WAIT' AND session.db = ?",
                [CollisionEnvironment::DATABASE],
            );

            if ((int) $waiting->total > 0) {
                return;
            }

            // InnoDB refreshes this list only after 0.1 seconds without a read,
            // so polling faster than that would keep reading the same stale copy.
            usleep(200000);
        } while (microtime(true) < $deadline);

        $sessions = DB::connection(CollisionEnvironment::CONNECTION)->select(
            CollisionEnvironment::usesPostgres()
                ? 'SELECT pid, state, wait_event_type, query FROM pg_stat_activity WHERE datname = ?'
                : 'SELECT session.id, session.state, session.info, trx.trx_state
                     FROM information_schema.processlist AS session
                     LEFT JOIN information_schema.innodb_trx AS trx ON trx.trx_mysql_thread_id = session.id
                    WHERE session.db = ?',
            [CollisionEnvironment::DATABASE],
        );

        $this->fail(sprintf(
            '%s: the worker never waited on the row, so this order of events did not happen. Sessions: %s',
            $context,
            json_encode($sessions, JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * Fails the test with a clear reason when any worker timed out, printed
     * nothing, or threw. Returns the payloads in launch order.
     *
     * @param  list<array{label: string, exit_code: ?int, timed_out: bool, payload: ?array<string, mixed>, stderr: string}>  $reports
     * @return list<array<string, mixed>>
     */
    protected function assertWorkersFinished(array $reports, string $context): array
    {
        $payloads = [];

        foreach ($reports as $report) {
            if ($report['timed_out']) {
                $this->fail(sprintf(
                    '%s: worker %s ran past the %d second limit and was killed. A hang here means a deadlock or a wait that never ends.',
                    $context,
                    $report['label'],
                    CollisionWorkerBatch::TIMEOUT_SECONDS,
                ));
            }

            if ($report['payload'] === null) {
                $this->fail(sprintf(
                    '%s: worker %s printed no result (exit %s). stderr: %s',
                    $context,
                    $report['label'],
                    var_export($report['exit_code'], true),
                    $report['stderr'],
                ));
            }

            if ($report['payload']['error'] !== null) {
                $this->fail(sprintf('%s: worker %s threw %s', $context, $report['label'], $report['payload']['error']));
            }

            $payloads[] = $report['payload'];
        }

        return $payloads;
    }

    /**
     * Counts the rounds in which every worker's contended call overlapped in
     * time: the latest start came before the earliest end. Fails when fewer
     * than half the rounds overlapped, because then the contention never
     * really happened and a passing result would prove nothing.
     *
     * @param  list<list<array<string, mixed>>>  $rounds  worker payloads, grouped by round
     */
    protected function assertWindowsOverlapInMostRounds(array $rounds, string $context): int
    {
        $overlapped = 0;

        foreach ($rounds as $payloads) {
            $latestStart = max(array_column($payloads, 'start_us'));
            $earliestEnd = min(array_column($payloads, 'end_us'));

            if ($latestStart < $earliestEnd) {
                $overlapped++;
            }
        }

        $total = count($rounds);

        if (getenv('STEP_DISPATCHER_COLLISION_REPORT') === '1') {
            fwrite(STDERR, sprintf("\n[collision] %s: %d of %d rounds overlapped\n", $context, $overlapped, $total));
        }

        if ($overlapped * 2 < $total) {
            $this->fail(sprintf(
                '%s: only %d of %d rounds had overlapping worker windows. Fewer than half collided, so the assertions prove nothing.',
                $context,
                $overlapped,
                $total,
            ));
        }

        return $overlapped;
    }

    private function makeWorkDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.self::WORK_DIRECTORY_PREFIX.bin2hex(random_bytes(6));

        foreach (['flag', 'cache'] as $child) {
            mkdir($directory.DIRECTORY_SEPARATOR.$child, 0o775, true);
        }

        return $directory;
    }

    /**
     * Removes only the work directory this test created, identified by its
     * unique prefix and the path recorded at creation.
     */
    private function removeWorkDirectory(): void
    {
        if (! isset($this->workDirectory)
            || ! str_starts_with(basename($this->workDirectory), self::WORK_DIRECTORY_PREFIX)
            || ! is_dir($this->workDirectory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->workDirectory);
    }
}
