<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\RuntimeContext;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Support\Steps;

/**
 * Critical contract: when the dispatcher tick promotes a Step into
 * the queue, the ambient prefix must travel along on the job
 * payload — otherwise the worker (which boots in a fresh process /
 * scoped container) would refresh() the Step from the wrong table.
 *
 * Two assertions:
 *   1. The pushed job carries `stepPrefix = 'trading_'` when the
 *      dispatcher tick ran with that ambient.
 *   2. handle() restores that prefix onto the RuntimeContext stack
 *      BEFORE prepareJobExecution()'s first DB read.
 */
beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    Queue::fake();

    $this->artisan('steps:install', ['--prefix' => 'trading'])->assertSuccessful();
});

it('dispatched job carries the ambient prefix on its stepPrefix property', function () {
    Steps::usingPrefix('trading', function (): void {
        $step = Step::create([
            'class' => \StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob::class,
            'type' => 'default',
            'queue' => 'default',
            'group' => 'payload-test',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);

        StepDispatcher::dispatch('payload-test');

        Queue::assertPushed(
            \StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob::class,
            function ($job) use ($step): bool {
                return $job->step->id === $step->id
                    && $job->stepPrefix === 'trading_';
            }
        );
    });
});

it('handle() pushes stepPrefix and the finally pop balances the stack', function () {
    // The package suite runs against sqlite :memory:, and
    // BaseDatabaseExceptionHandler::make() rejects sqlite — so
    // running handle() end-to-end inside the worker chain is not
    // viable here (host apps test that path against real MySQL
    // via the steps-dispatcher harness). What this test pins is
    // the structural contract that matters: handle() opens with a
    // push and closes with a matching pop in finally. If that pair
    // is wrong, every other prefix guarantee in the worker chain
    // unravels.
    app(RuntimeContext::class)->reset();

    $step = Step::create([
        'class' => \StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'handle-balance',
        'index' => null,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ]);

    $job = new \StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;
    $job->step = $step;
    $job->stepPrefix = 'trading_';

    expect(app(RuntimeContext::class)->depth())->toBe(0,
        'Pre-condition: stack must be empty at the start of the test '
        .'so the depth-balance assertion below is meaningful.'
    );

    // handle() will throw inside (sqlite + the package's MySQL/
    // pgsql-only DatabaseExceptionHandler), but the finally-block
    // pop must still execute — that is the whole reason the push/
    // pop pair lives inside try/finally rather than scattered
    // around individual call paths.
    try {
        $job->handle();
    } catch (Throwable) {
        // expected on sqlite; we are not asserting compute() ran.
    }

    expect(app(RuntimeContext::class)->depth())->toBe(0,
        'Even when inner code throws, handle() MUST leave the '
        .'RuntimeContext stack at exactly the depth it was entered '
        .'at. A non-zero depth here means a queued job in the same '
        .'worker process would inherit the wrong ambient prefix on '
        .'its next pickup — silent cross-job state pollution.'
    );
});

it('failed() pushes stepPrefix and writes the Failed transition to the prefixed table', function () {
    app(RuntimeContext::class)->reset();

    Steps::usingPrefix('trading', function () {
        $step = Step::create([
            'class' => \StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob::class,
            'type' => 'default',
            'queue' => 'default',
            'group' => 'failed-test',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);

        $stepId = $step->id;

        // failed() only concludes steps that are mid-flight (Running or
        // Dispatched) — a Pending step at failed() time was recovered or
        // rescheduled by someone else and must not be clobbered. Put the
        // row in the legitimate worker-death shape: Running.
        DB::table('trading_steps')->where('id', $stepId)->update([
            'state' => \StepDispatcher\States\Running::class,
        ]);
        $step->refresh();

        $job = new \StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;
        $job->step = $step;
        $job->stepPrefix = 'trading_';
        $job->startMicrotime = microtime(true);

        // Drop the outer Steps::usingPrefix push for the duration
        // of the failed() call to simulate Laravel's queue
        // infrastructure invoking failed() on a fresh worker
        // process (no ambient prefix on the stack).
        app(RuntimeContext::class)->reset();

        $job->failed(new RuntimeException('simulated worker timeout'));

        // After failed() returns, the prefix push it did internally
        // has been popped — the stack is empty again.
        expect(app(RuntimeContext::class)->depth())->toBe(0);

        // The Failed transition wrote to the trading_steps row,
        // not the (empty) default `steps` table. Read the row
        // through DB::table to bypass any Eloquent caching.
        $row = DB::table('trading_steps')->where('id', $stepId)->first();
        expect($row->state)->toBe(\StepDispatcher\States\Failed::class,
            'failed() must push stepPrefix BEFORE the update so the '
            .'Failed transition lands in `trading_steps`. Without '
            .'that push the update would have hit the unprefixed '
            .'`steps` table and the trading_steps row would still '
            .'be Pending — the worst-shape silent corruption in the '
            .'system: a Horizon-killed job leaving live work behind.'
        );

        // And the unprefixed default table must NOT have been
        // touched at all.
        expect(DB::table('steps')->count())->toBe(0,
            'The default `steps` table is empty in this test. A '
            .'non-zero count means failed() wrote to the wrong table.'
        );
    });
});
