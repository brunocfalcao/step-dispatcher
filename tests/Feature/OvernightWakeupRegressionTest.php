<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;

beforeEach(function (): void {
    $this->flagRoot = sys_get_temp_dir().'/step-dispatcher-wakeup-'.bin2hex(random_bytes(8));
    config()->set('step-dispatcher.flag_path', $this->flagRoot);
});

afterEach(function (): void {
    File::deleteDirectory($this->flagRoot);
});

it('keeps the wake-up flag when work arrives during idle finalization', function (): void {
    StepDispatcher::activate();

    $created = $this->flagRoot.'/created';
    $ready = $this->flagRoot.'/ready';
    $activated = $this->flagRoot.'/activated';
    $childPid = null;
    $childStatus = null;
    $interleavingTriggered = false;

    $waitForChild = static function (int $pid): int {
        $status = 0;
        $deadline = microtime(true) + 3.0;

        while (microtime(true) < $deadline) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);

            if ($result === $pid || $result === -1) {
                return $status;
            }

            usleep(1_000);
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
        }

        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);

            if ($result === $pid || $result === -1) {
                return $status;
            }

            usleep(1_000);
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGKILL);
        }

        pcntl_waitpid($pid, $status);

        return $status;
    };

    DB::listen(function (object $query) use (&$childPid, &$interleavingTriggered, $created, $ready, $activated): void {
        $sql = mb_strtolower(mb_ltrim($query->sql));

        if ($interleavingTriggered
            || ! str_starts_with($sql, 'select exists')
            || ! str_contains($sql, 'steps')
            || ! str_contains($sql, 'state')) {
            return;
        }

        $interleavingTriggered = true;

        // The idle query has already returned false when this listener runs.
        // Insert the accepted work before the finalizer can unlink the flag.
        DB::table(Step::tableName())->insert([
            'block_uuid' => 'wakeup-'.bin2hex(random_bytes(8)),
            'type' => 'default',
            'group' => 'wakeup-regression',
            'state' => Pending::class,
            'class' => 'App\\Jobs\\WakeupRegressionJob',
            'index' => 1,
            'queue' => 'default',
            'workflow_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('pcntl is required for the wake-up interleaving regression');
        }

        $childPid = pcntl_fork();

        if ($childPid === -1) {
            throw new RuntimeException('Unable to fork the wake-up interleaving worker');
        }

        if ($childPid === 0) {
            file_put_contents($ready, 'ready');

            $deadline = microtime(true) + 2.0;
            while (! file_exists($created) && microtime(true) < $deadline) {
                usleep(1_000);
            }

            StepDispatcher::activate();
            file_put_contents($activated, 'activated');
            exit(0);
        }

        $deadline = microtime(true) + 2.0;
        while (! file_exists($ready) && microtime(true) < $deadline) {
            usleep(1_000);
        }

        file_put_contents($created, 'created');

        // The unfixed finalizer lets the child activate before this callback
        // returns, then unlinks the flag. The fixed finalizer holds the same
        // lock while checking and deactivating, so the child activates only
        // after that critical section ends.
        $deadline = microtime(true) + 2.0;
        while (! file_exists($activated) && microtime(true) < $deadline) {
            usleep(1_000);
        }
    });

    try {
        StepDispatcher::dispatch('wakeup-regression');

        if ($childPid !== null) {
            $childStatus = $waitForChild($childPid);
            expect(pcntl_wifexited($childStatus))->toBeTrue()
                ->and(pcntl_wexitstatus($childStatus))->toBe(0);
        }

        expect(DB::table(Step::tableName())
            ->where('group', 'wakeup-regression')
            ->where('state', Pending::class)
            ->exists())->toBeTrue()
            ->and(file_exists($this->flagRoot.'/active.flag'))->toBeTrue();
    } finally {
        if ($childPid !== null && $childStatus === null) {
            $waitForChild($childPid);
        }
    }
});

it('reasserts the flag through the connection after-commit callback', function (): void {
    $connection = DB::connection();
    $originalManager = app('db.transactions');
    $manager = new class
    {
        /** @var list<callable> */
        public array $callbacks = [];

        public function addCallback(callable $callback): void
        {
            $this->callbacks[] = $callback;
        }
    };

    $connection->setTransactionManager($manager);

    try {
        Step::create([
            'class' => 'App\\Jobs\\WakeupRegressionJob',
            'type' => 'default',
            'group' => 'wakeup-after-commit',
            'index' => 1,
            'block_uuid' => 'wakeup-after-commit-'.bin2hex(random_bytes(8)),
            'state' => Pending::class,
        ]);

        StepDispatcher::deactivate();
    } finally {
        $connection->setTransactionManager($originalManager);
    }

    expect($manager->callbacks)->toHaveCount(1);

    ($manager->callbacks[0])();

    expect(StepDispatcher::isActive())->toBeTrue();
});

it('still deactivates an idle dispatcher with no active rows', function (): void {
    StepDispatcher::activate();

    StepDispatcher::dispatch('wakeup-idle-control');

    expect(StepDispatcher::isActive())->toBeFalse();
});
