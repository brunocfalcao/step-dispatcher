<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\CollisionTestJob;
use StepDispatcher\Tests\Fixtures\ObsoleteAttemptTestJob;

/*
|--------------------------------------------------------------------------
| A project's reaction whose own query fails
|--------------------------------------------------------------------------
|
| Real commits on a real server. On Postgres a failed statement aborts the
| whole transaction around it, so a result is only safe from a failing reaction
| if the save was committed before the reaction ran. Run this on Postgres with
| STEP_DISPATCHER_COLLISION_DRIVER=pgsql; on MySQL these results must match.
|
*/

function failingReaction(int $stepId, string $state, string $kind): ArrayObject
{
    $fired = new ArrayObject;

    Step::updated(static function (Step $step) use ($stepId, $state, $kind, $fired): void {
        if ($step->id !== $stepId || ! $step->wasChanged('state') || ! $step->state instanceof $state) {
            return;
        }

        $fired->append(true);

        if ($kind === 'a failing query') {
            DB::select('select * from reaction_table_that_does_not_exist');
        }

        throw new RuntimeException('plain reaction failure');
    });

    return $fired;
}

function reactionStep(array $columns = []): Step
{
    $step = Step::create([
        'class' => CollisionTestJob::class,
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'reaction-failure',
        'state' => Pending::class,
    ]);

    if ($columns !== []) {
        DB::table(Step::tableName())->where('id', $step->id)->update($columns);
    }

    return $step->fresh();
}

function runReactionJob(Step $step): CollisionTestJob
{
    $job = new CollisionTestJob;
    $job->step = $step;

    try {
        $job->handle();
    } catch (Throwable) {
        // What the queue does with it is decided per test.
    }

    return $job;
}

function reactionRuns(int $stepId): int
{
    return DB::table('collision_runs')->where('step_id', $stepId)->count();
}

dataset('reaction failures', ['a failing query', 'a plain exception']);

it('keeps a parent result when the reaction to it fails', function (string $sweep, string $childState, string $expected, string $kind): void {
    $childBlock = (string) Str::uuid();
    $parent = reactionStep(['state' => Running::class, 'child_block_uuid' => $childBlock]);
    $child = reactionStep(['state' => $childState, 'block_uuid' => $childBlock]);

    $fired = failingReaction($parent->id, $expected, $kind);

    StepDispatcher::{$sweep}('reaction-failure');
    StepDispatcher::{$sweep}('reaction-failure');

    expect(Step::find($parent->id)->state)->toBeInstanceOf($expected)
        ->and(Step::find($child->id)->state)->toBeInstanceOf($childState)
        ->and($fired)->toHaveCount(1)
        ->and(DB::transactionLevel())->toBe(0);
})->with([
    'completion sweep' => ['transitionParentsToComplete', Completed::class, Completed::class],
    'failure sweep' => ['transitionParentsToFailed', Failed::class, Failed::class],
    'stop sweep' => ['transitionParentsToStopped', Stopped::class, Stopped::class],
])->with('reaction failures');

it('keeps a cancelled step when the reaction to it fails', function (string $kind): void {
    $step = reactionStep();

    $fired = failingReaction($step->id, Cancelled::class, $kind);

    StepDispatcher::batchTransitionSteps([$step->id], Cancelled::class);
    StepDispatcher::batchTransitionSteps([$step->id], Cancelled::class);

    expect(Step::find($step->id)->state)->toBeInstanceOf(Cancelled::class)
        ->and($fired)->toHaveCount(1)
        ->and(DB::transactionLevel())->toBe(0);
})->with('reaction failures');

it('keeps a finished job when the reaction to it fails, and never runs it again', function (string $kind): void {
    $step = reactionStep();

    $fired = failingReaction($step->id, Completed::class, $kind);

    $job = runReactionJob($step);

    $row = Step::find($step->id);

    expect($row->state)->toBeInstanceOf(Completed::class)
        ->and($row->response)->not->toBeNull()
        ->and($row->error_message)->not->toBeNull()
        ->and(reactionRuns($step->id))->toBe(1)
        ->and($fired)->toHaveCount(1)
        ->and(DB::transactionLevel())->toBe(0);

    // The queue gives up on the job that threw.
    $job->failed(new RuntimeException('queue gave up'));

    expect(Step::find($step->id)->state)->toBeInstanceOf(Completed::class);

    // Recovery finds nothing left to run, however late it comes.
    $this->travel(1)->days();
    Artisan::call('steps:recover-stale');

    $final = Step::find($step->id);

    expect($final->state)->toBeInstanceOf(Completed::class)
        ->and($final->retries)->toBe(0)
        ->and(reactionRuns($step->id))->toBe(1);
})->with('reaction failures');

it('keeps a stopped job when the reaction to it fails', function (string $kind): void {
    $step = reactionStep(['class' => ObsoleteAttemptTestJob::class]);

    ObsoleteAttemptTestJob::reset();
    ObsoleteAttemptTestJob::$act = static fn ($job) => $job->stopJob();

    $fired = failingReaction($step->id, Stopped::class, $kind);

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;

    try {
        $job->handle();
    } catch (Throwable) {
        // The row below is what matters.
    }

    expect(Step::find($step->id)->state)->toBeInstanceOf(Stopped::class)
        ->and($fired)->toHaveCount(1)
        ->and(DB::transactionLevel())->toBe(0);
})->with('reaction failures');

it('ends a step Failed and does not write a state whose commit failed', function (string $state, ?Closure $act): void {
    $step = reactionStep(['class' => ObsoleteAttemptTestJob::class]);

    ObsoleteAttemptTestJob::reset();
    ObsoleteAttemptTestJob::$act = $act;

    $armed = false;

    Step::updating(static function (Step $row) use ($step, $state, &$armed): void {
        if ($row->id === $step->id && $row->state instanceof $state) {
            $armed = true;
        }
    });

    Event::listen(TransactionCommitting::class, static function () use (&$armed): void {
        if ($armed) {
            $armed = false;

            throw new RuntimeException('commit failed');
        }
    });

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;
    $job->handle();

    $row = Step::find($step->id);

    expect($row->state)->toBeInstanceOf(Failed::class)
        ->and($row->retries)->toBe(0)
        ->and($row->error_message)->toContain('commit failed')
        ->and(DB::transactionLevel())->toBe(0);
})->with([
    'a finish' => [Completed::class, null],
    'a stop' => [Stopped::class, static fn ($job) => $job->stopJob()],
    'a retry' => [Pending::class, static fn ($job) => $job->retryJob()],
]);

it('ends a step Failed when the transaction of a project undoes the state its job saved', function (string $state, Closure $move, string $kind): void {
    $step = reactionStep(['class' => ObsoleteAttemptTestJob::class]);

    ObsoleteAttemptTestJob::reset();
    ObsoleteAttemptTestJob::$act = static fn ($job) => DB::transaction(static fn () => $move($job));

    $fired = failingReaction($step->id, $state, $kind);

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;
    $job->handle();

    $row = Step::find($step->id);

    expect($row->state)->toBeInstanceOf(Failed::class)
        ->and($row->retries)->toBe(0)
        ->and($row->error_message)->not->toBeNull()
        ->and(count($fired))->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);
})->with([
    'a stop' => [Stopped::class, static fn ($job) => $job->stopJob()],
    'a retry' => [Pending::class, static fn ($job) => $job->retryJob()],
])->with('reaction failures');

it('stays loud when the reaction fails inside a transaction the project opened', function (string $kind): void {
    $step = reactionStep();
    $projectRow = reactionStep();

    failingReaction($step->id, Completed::class, $kind);

    $job = new CollisionTestJob;
    $job->step = $step;

    $escaped = null;

    try {
        DB::transaction(static function () use ($projectRow, $job): void {
            DB::table(Step::tableName())->where('id', $projectRow->id)->update(['label' => 'project write']);

            $job->handle();
        });
    } catch (Throwable $e) {
        $escaped = $e;
    }

    expect($escaped)->not->toBeNull()
        ->and(Step::find($step->id)->state)->toBeInstanceOf(Pending::class)
        ->and(Step::find($projectRow->id)->label)->toBeNull()
        ->and(DB::transactionLevel())->toBe(0);
})->with('reaction failures');
