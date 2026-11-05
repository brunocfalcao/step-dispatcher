<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\ObsoleteAttemptTestJob;

/*
|--------------------------------------------------------------------------
| The row lock ends when the state is saved
|--------------------------------------------------------------------------
|
| A project's reaction to a state change runs after the lock is released, with
| the save already committed, exactly where it runs in a save with no lock. A
| reaction that fails cannot take the result with it on any database, including
| Postgres, where a failed statement aborts the whole transaction around it.
| The real failure on a real server is covered by tests/Collision.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    ObsoleteAttemptTestJob::reset();
});

function lockEndStep(array $columns = []): Step
{
    $step = Step::create([
        'class' => ObsoleteAttemptTestJob::class,
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'lock-end',
        'state' => Pending::class,
    ]);

    if ($columns !== []) {
        DB::table(Step::tableName())->where('id', $step->id)->update($columns);
    }

    return $step->fresh();
}

function lockEndParent(string $childState): Step
{
    $childBlock = (string) Str::uuid();
    $parent = lockEndStep(['state' => Running::class, 'child_block_uuid' => $childBlock]);
    lockEndStep(['state' => $childState, 'block_uuid' => $childBlock]);

    return $parent;
}

function transactionLevelsSeenBy(string $event, int $stepId): ArrayObject
{
    $levels = new ArrayObject;

    Step::{$event}(static function (Step $step) use ($stepId, $levels): void {
        if ($step->id === $stepId && $step->wasChanged('state') && ! $step->state instanceof Running) {
            $levels->append(DB::transactionLevel());
        }
    });

    return $levels;
}

it('runs reactions to a parent sweep after the lock has ended', function (string $sweep, string $state): void {
    $parent = lockEndParent($state);
    $baseline = DB::transactionLevel();
    $updated = transactionLevelsSeenBy('updated', $parent->id);
    $saved = transactionLevelsSeenBy('saved', $parent->id);

    StepDispatcher::{$sweep}('lock-end');

    expect(Step::find($parent->id)->state)->toBeInstanceOf($state)
        ->and($updated->getArrayCopy())->toBe([$baseline])
        ->and($saved->getArrayCopy())->toBe([$baseline])
        ->and(DB::transactionLevel())->toBe($baseline);
})->with([
    'completion' => ['transitionParentsToComplete', Completed::class],
    'failure' => ['transitionParentsToFailed', Failed::class],
    'stop' => ['transitionParentsToStopped', Stopped::class],
]);

it('runs reactions to a cancel batch after the lock has ended', function (): void {
    $step = lockEndStep();
    $baseline = DB::transactionLevel();
    $updated = transactionLevelsSeenBy('updated', $step->id);

    StepDispatcher::batchTransitionSteps([$step->id], Cancelled::class);

    expect(Step::find($step->id)->state)->toBeInstanceOf(Cancelled::class)
        ->and($updated->getArrayCopy())->toBe([$baseline])
        ->and(DB::transactionLevel())->toBe($baseline);
});

it('runs reactions to a job outcome after the lock has ended', function (string $state, ?Closure $act): void {
    ObsoleteAttemptTestJob::$act = $act;

    $step = lockEndStep();
    $baseline = DB::transactionLevel();
    $updated = transactionLevelsSeenBy('updated', $step->id);

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;
    $job->handle();

    expect(Step::find($step->id)->state)->toBeInstanceOf($state)
        ->and($updated->getArrayCopy())->toBe([$baseline])
        ->and(DB::transactionLevel())->toBe($baseline);
})->with([
    'finishes' => [Completed::class, null],
    'stops itself' => [Stopped::class, static fn ($job) => $job->stopJob()],
    'skips itself' => [Skipped::class, static fn ($job) => $job->skipJob()],
    'retries itself' => [Pending::class, static fn ($job) => $job->retryJob()],
    'reschedules itself' => [Pending::class, static fn ($job) => $job->rescheduleWithoutRetry()],
    'fails' => [Failed::class, static function (): void {
        throw new RuntimeException('own failure');
    }],
]);

it('holds the lock through the writes that come before the state change', function (): void {
    ObsoleteAttemptTestJob::$act = static function (): void {
        throw new RuntimeException('own failure');
    };

    $step = lockEndStep();
    $baseline = DB::transactionLevel();
    $levels = new ArrayObject;

    Step::updated(static function (Step $row) use ($step, $levels): void {
        if ($row->id === $step->id && $row->wasChanged('error_message') && ! $row->wasChanged('state')) {
            $levels->append(DB::transactionLevel());
        }
    });

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;
    $job->handle();

    expect($levels->getArrayCopy())->toBe([$baseline + 1]);
});

it('commits the state before a reaction fails, and leaves no transaction open', function (): void {
    $parent = lockEndParent(Completed::class);
    $baseline = DB::transactionLevel();
    $committedLevel = null;

    Step::updated(static function (Step $row) use ($parent, &$committedLevel): void {
        if ($row->id === $parent->id && $row->wasChanged('state')) {
            $committedLevel = DB::transactionLevel();

            throw new RuntimeException('reaction failed');
        }
    });

    StepDispatcher::transitionParentsToComplete('lock-end');

    expect($committedLevel)->toBe($baseline)
        ->and(Step::find($parent->id)->state)->toBeInstanceOf(Completed::class)
        ->and(DB::transactionLevel())->toBe($baseline);
});

it('leaves no transaction open when a save is refused', function (): void {
    $parent = lockEndParent(Completed::class);
    $baseline = DB::transactionLevel();

    Step::updating(static function (Step $row) use ($parent): void {
        if ($row->id === $parent->id && $row->isDirty('state')) {
            throw new RuntimeException('save refused');
        }
    });

    StepDispatcher::transitionParentsToComplete('lock-end');

    expect(Step::find($parent->id)->state)->toBeInstanceOf(Running::class)
        ->and(DB::transactionLevel())->toBe($baseline);
});

it('returns what the write returns and ends the lock when nothing changes state', function (): void {
    $step = lockEndStep();
    $baseline = DB::transactionLevel();

    $result = Step::whileRowLocked($step->id, static function (?Step $locked) use ($baseline): string {
        expect($locked)->not->toBeNull()
            ->and(DB::transactionLevel())->toBe($baseline + 1);

        return 'written';
    });

    expect($result)->toBe('written')
        ->and(DB::transactionLevel())->toBe($baseline);
});

it('hands a missing row to the write and ends the lock', function (): void {
    $baseline = DB::transactionLevel();

    $result = Step::whileRowLocked(987654, static fn (?Step $locked): bool => $locked === null);

    expect($result)->toBeTrue()
        ->and(DB::transactionLevel())->toBe($baseline);
});

it('throws the failure of the write itself and ends the lock', function (): void {
    $step = lockEndStep();
    $baseline = DB::transactionLevel();

    expect(static fn () => Step::whileRowLocked($step->id, static function (): void {
        throw new DomainException('write failed');
    }))->toThrow(DomainException::class, 'write failed')
        ->and(DB::transactionLevel())->toBe($baseline);
});

it('ends every lock when one is taken inside another on the same row', function (): void {
    $step = lockEndStep();
    $baseline = DB::transactionLevel();
    $levels = transactionLevelsSeenBy('updated', $step->id);

    Step::whileRowLocked($step->id, static function () use ($step): void {
        Step::whileRowLocked($step->id, static function (?Step $locked): void {
            $locked->state->transitionTo(Cancelled::class);
        });
    });

    expect(Step::find($step->id)->state)->toBeInstanceOf(Cancelled::class)
        ->and($levels->getArrayCopy())->toBe([$baseline])
        ->and(DB::transactionLevel())->toBe($baseline);
});

it('leaves the lock of another row in place while one row is saved', function (): void {
    $held = lockEndStep();
    $moved = lockEndStep();
    $baseline = DB::transactionLevel();
    $levels = transactionLevelsSeenBy('updated', $moved->id);

    Step::whileRowLocked($held->id, static function () use ($moved, $baseline): void {
        Step::whileRowLocked($moved->id, static function (?Step $locked): void {
            $locked->state->transitionTo(Cancelled::class);
        });

        expect(DB::transactionLevel())->toBe($baseline + 1);
    });

    expect($levels->getArrayCopy())->toBe([$baseline + 1])
        ->and(DB::transactionLevel())->toBe($baseline);
});

it('works inside a transaction the project opened', function (): void {
    $parent = lockEndParent(Completed::class);
    $baseline = DB::transactionLevel();
    $updated = transactionLevelsSeenBy('updated', $parent->id);

    DB::transaction(static function () use ($baseline): void {
        StepDispatcher::transitionParentsToComplete('lock-end');

        expect(DB::transactionLevel())->toBe($baseline + 1);
    });

    expect(Step::find($parent->id)->state)->toBeInstanceOf(Completed::class)
        ->and($updated->getArrayCopy())->toBe([$baseline + 1])
        ->and(DB::transactionLevel())->toBe($baseline);
});

it('moves a step inside a save with no lock as it always did', function (): void {
    $step = lockEndStep();
    $baseline = DB::transactionLevel();
    $levels = transactionLevelsSeenBy('updated', $step->id);

    $step->state->transitionTo(Cancelled::class);

    expect($levels->getArrayCopy())->toBe([$baseline])
        ->and(DB::transactionLevel())->toBe($baseline);
});
