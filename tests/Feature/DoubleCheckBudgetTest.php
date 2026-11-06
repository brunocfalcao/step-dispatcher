<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\DoubleCheckTestJob;
use StepDispatcher\Tests\Fixtures\ObsoleteAttemptPlainTestJob;

/*
|--------------------------------------------------------------------------
| Double-Check Verification Budget
|--------------------------------------------------------------------------
|
| doubleCheck() is the post-compute confirmation gate for exchange-facing
| work. Its contract:
|
|   - passes first try   → step completes, double_check pinned to 99
|   - fails              → double_check increments, step retries
|   - budget exhausted   → the step FAILS (fail-closed) rather than silently
|                          completing against unverified state
|   - own retry          → an attempt that asked for its own retry or reschedule
|                          (or skipped or stopped) is not checked, sealed or
|                          incremented; the next attempt computes again
|   - sealed (99)        → a later attempt does not compute, but checks again:
|                          a passing check finishes the step, a failing one
|                          fails it and keeps the seal
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    DoubleCheckTestJob::reset();
});

afterEach(function (): void {
    DoubleCheckTestJob::reset();
});

/**
 * @param  array<string, mixed>  $columns
 */
function doubleCheckStep(int $doubleCheck = 0, array $columns = []): Step
{
    $step = Step::create([
        'class' => DoubleCheckTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ]);

    if ($doubleCheck !== 0) {
        $columns['double_check'] = $doubleCheck;
    }

    if ($columns !== []) {
        DB::table(Step::tableName())->where('id', $step->id)->update($columns);
    }

    return $step->fresh();
}

function runDoubleCheck(Step $step): Step
{
    $job = new DoubleCheckTestJob;
    $job->step = $step;
    $job->handle();

    return $step->fresh();
}

it('completes and pins double_check to 99 when verification passes', function (): void {
    DoubleCheckTestJob::$pass = true;

    $fresh = runDoubleCheck(doubleCheckStep());

    expect($fresh->state)->toBeInstanceOf(Completed::class)
        ->and((int) $fresh->double_check)->toBe(99)
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(1);
});

it('increments double_check and retries when verification fails', function (): void {
    DoubleCheckTestJob::$pass = false;

    $fresh = runDoubleCheck(doubleCheckStep());

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and((int) $fresh->double_check)->toBe(1);
});

it('fails the step once the double-check budget is exhausted', function (): void {
    DoubleCheckTestJob::$pass = false;

    // double_check already at 2 — the budget-exhaustion branch fires before
    // compute(), failing the step rather than completing it unverified.
    $fresh = runDoubleCheck(doubleCheckStep(2));

    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and($fresh->error_message)->toContain('verification budget exhausted')
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(0);
});

it('records how long the attempt ran when an exhausted budget fails the step', function (): void {
    DoubleCheckTestJob::$startDelayMicroseconds = 3000;

    $fresh = runDoubleCheck(doubleCheckStep(2));

    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and((int) $fresh->duration)->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| A check that passed stands
|--------------------------------------------------------------------------
|
| double_check 99 is the seal written when doubleCheck() passed. An attempt
| that reaches a sealed step does not compute, but it runs doubleCheck()
| again, because the state it confirmed may have changed since. A passing
| check goes on through confirmOrRetry() and complete(); a failing check
| fails the step. The seal stays 99 either way.
|
*/

const SEALED_RECHECK_FAILED_MESSAGE = 'no longer confirms a step that had already passed';

function sealedStepState(Step $step): array
{
    $fresh = $step->fresh();

    return [
        'state' => $fresh->state::class,
        'double_check' => (int) $fresh->double_check,
        'retries' => (int) $fresh->retries,
        'compute' => DoubleCheckTestJob::$computeRuns,
        'doubleCheck' => DoubleCheckTestJob::$doubleCheckRuns,
        'complete' => DoubleCheckTestJob::$completeRuns,
    ];
}

it('finishes a sealed step on its next attempt without computing, after checking again', function (): void {
    $step = doubleCheckStep(99, ['retries' => 1]);

    expect(sealedStepState($step))->toBe([
        'state' => Pending::class, 'double_check' => 99, 'retries' => 1,
        'compute' => 0, 'doubleCheck' => 0, 'complete' => 0,
    ]);

    $fresh = runDoubleCheck($step);

    expect(sealedStepState($step))->toBe([
        'state' => Completed::class, 'double_check' => 99, 'retries' => 1,
        'compute' => 0, 'doubleCheck' => 1, 'complete' => 1,
    ])
        ->and($fresh->error_message)->toBeNull()
        ->and($fresh->completed_at)->not->toBeNull();
});

dataset('starting double_check values', [
    'fresh, check passes' => [0, true, Completed::class, 99, 1, 1],
    'fresh, check fails' => [0, false, Pending::class, 1, 1, 1],
    'one failure, check passes' => [1, true, Completed::class, 99, 0, 1],
    'one failure, check fails' => [1, false, Pending::class, 2, 0, 1],
    'budget spent (2), check would pass' => [2, true, Failed::class, 2, 0, 0],
    'budget spent (2), check would fail' => [2, false, Failed::class, 2, 0, 0],
    'above the budget (3), check would pass' => [3, true, Failed::class, 3, 0, 0],
    'above the budget (3), check would fail' => [3, false, Failed::class, 3, 0, 0],
    'sealed (99), check passes again' => [99, true, Completed::class, 99, 0, 1],
    'sealed (99), check no longer passes' => [99, false, Failed::class, 99, 0, 1],
]);

it('treats each starting double_check value as the budget rules say', function (int $start, bool $pass, string $state, int $doubleCheck, int $compute, int $checks): void {
    DoubleCheckTestJob::$pass = $pass;

    $step = doubleCheckStep($start);

    $fresh = runDoubleCheck($step);

    expect($fresh->state)->toBeInstanceOf($state)
        ->and((int) $fresh->double_check)->toBe($doubleCheck)
        ->and(DoubleCheckTestJob::$computeRuns)->toBe($compute)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe($checks);

    if ($state === Failed::class && $start === 99) {
        expect($fresh->error_message)->toContain(SEALED_RECHECK_FAILED_MESSAGE)
            ->and($fresh->error_message)->not->toContain('verification budget exhausted');
    } elseif ($state === Failed::class) {
        expect($fresh->error_message)->toContain('verification budget exhausted');
    } else {
        expect($fresh->error_message ?? '')->not->toContain('verification budget exhausted');
    }
})->with('starting double_check values');

dataset('ways to stop an attempt after the check passed', [
    'complete() throws an exception classified as a retry' => [
        static function ($job, int $run): void {
            if ($run === 1) {
                throw new RuntimeException(DoubleCheckTestJob::RETRYABLE_MESSAGE);
            }
        },
        1,
        false,
    ],
    'complete() asks for a retry' => [
        static function ($job, int $run): void {
            if ($run === 1) {
                $job->retryJob();
            }
        },
        1,
        false,
    ],
    'complete() reschedules without a retry' => [
        static function ($job, int $run): void {
            if ($run === 1) {
                $job->rescheduleWithoutRetry();
            }
        },
        0,
        true,
    ],
]);

it('finishes on the second attempt without repeating the work, after checking again', function (Closure $stop, int $retries, bool $throttled): void {
    $step = doubleCheckStep();

    DoubleCheckTestJob::$onComplete = $stop;

    $afterFirst = runDoubleCheck($step);

    expect($afterFirst->state)->toBeInstanceOf(Pending::class)
        ->and((int) $afterFirst->double_check)->toBe(99)
        ->and((int) $afterFirst->retries)->toBe($retries)
        ->and((bool) $afterFirst->is_throttled)->toBe($throttled)
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$completeRuns)->toBe(1);

    $final = runDoubleCheck($afterFirst);

    expect($final->state)->toBeInstanceOf(Completed::class)
        ->and((int) $final->double_check)->toBe(99)
        ->and((int) $final->retries)->toBe($retries)
        ->and($final->error_message ?? '')->not->toContain('verification budget exhausted')
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(2)
        ->and(DoubleCheckTestJob::$completeRuns)->toBe(2);
})->with('ways to stop an attempt after the check passed');

it('finishes on the second attempt when the first one asked for a retry from confirmOrRetry', function (): void {
    $step = doubleCheckStep();

    DoubleCheckTestJob::$onConfirm = static function ($job, int $run): void {
        if ($run === 1) {
            $job->retryJob();
        }
    };

    $afterFirst = runDoubleCheck($step);

    expect($afterFirst->state)->toBeInstanceOf(Pending::class)
        ->and((int) $afterFirst->double_check)->toBe(99)
        ->and((int) $afterFirst->retries)->toBe(1)
        ->and(DoubleCheckTestJob::$completeRuns)->toBe(0);

    $final = runDoubleCheck($afterFirst);

    expect($final->state)->toBeInstanceOf(Completed::class)
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(2)
        ->and(DoubleCheckTestJob::$confirmRuns)->toBe(2)
        ->and(DoubleCheckTestJob::$completeRuns)->toBe(1);
});

it('sends an unconfirmed result to the confirmation run, which never reaches the double check', function (): void {
    $step = doubleCheckStep();

    DoubleCheckTestJob::$confirms = false;

    $afterFirst = runDoubleCheck($step);

    expect($afterFirst->state)->toBeInstanceOf(Pending::class)
        ->and($afterFirst->execution_mode)->toBe('confirming-completion')
        ->and((int) $afterFirst->double_check)->toBe(99)
        ->and((int) $afterFirst->retries)->toBe(1)
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(1);

    DoubleCheckTestJob::$confirms = true;

    $final = runDoubleCheck($afterFirst);

    expect($final->state)->toBeInstanceOf(Completed::class)
        ->and((int) $final->double_check)->toBe(99)
        ->and($final->error_message)->toBeNull()
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$confirmRuns)->toBe(2);
});

it('sends a sealed retry whose result is unconfirmed to the confirmation run, and the next attempt finishes', function (): void {
    $step = doubleCheckStep(99, ['retries' => 1]);

    DoubleCheckTestJob::$confirms = false;

    $afterRetry = runDoubleCheck($step);

    expect($afterRetry->state)->toBeInstanceOf(Pending::class)
        ->and($afterRetry->execution_mode)->toBe('confirming-completion')
        ->and((int) $afterRetry->double_check)->toBe(99)
        ->and((int) $afterRetry->retries)->toBe(2)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(0);

    DoubleCheckTestJob::$confirms = true;

    $final = runDoubleCheck($afterRetry);

    expect($final->state)->toBeInstanceOf(Completed::class)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(0)
        ->and($final->error_message)->toBeNull();
});

it('fails a sealed retry with the error of its own hook, not the budget text', function (): void {
    $step = doubleCheckStep(99, ['retries' => 1]);

    DoubleCheckTestJob::$onConfirm = static function (): void {
        throw new RuntimeException('confirmation service is down');
    };

    $fresh = runDoubleCheck($step);

    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and($fresh->error_message)->toContain('confirmation service is down')
        ->and($fresh->error_message)->not->toContain('verification budget exhausted')
        ->and((int) $fresh->double_check)->toBe(99)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$completeRuns)->toBe(0);
});

it('fails a sealed step whose retries are already at the limit through the max-retries path', function (): void {
    $step = doubleCheckStep(99, ['retries' => 5]);

    $fresh = runDoubleCheck($step);

    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and($fresh->error_message)->toContain('Max retries (5) reached')
        ->and($fresh->error_message)->not->toContain('verification budget exhausted')
        ->and((int) $fresh->double_check)->toBe(99)
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(0)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(0)
        ->and(DoubleCheckTestJob::$completeRuns)->toBe(0);
});

it('lets recovery fail a sealed running step whose retries are exhausted', function (): void {
    $step = doubleCheckStep(99, [
        'state' => Running::class,
        'started_at' => now()->subHour()->format('Y-m-d H:i:s.u'),
        'retries' => 5,
    ]);

    Carbon::setTestNow(now()->addDay());
    Artisan::call('steps:recover-stale');

    $fresh = $step->fresh();

    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and((int) $fresh->retries)->toBe(5)
        ->and((int) $fresh->double_check)->toBe(99)
        ->and($fresh->error_message)->toContain('retries exhausted (5/5)');
});

it('lets recovery put a sealed running step back to Pending while retries remain', function (): void {
    $step = doubleCheckStep(99, [
        'state' => Running::class,
        'started_at' => now()->subHour()->format('Y-m-d H:i:s.u'),
    ]);

    Carbon::setTestNow(now()->addDay());
    Artisan::call('steps:recover-stale');

    $fresh = $step->fresh();

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and((int) $fresh->retries)->toBe(1)
        ->and((int) $fresh->double_check)->toBe(99);

    $final = runDoubleCheck($fresh);

    expect($final->state)->toBeInstanceOf(Completed::class)
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(0)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$completeRuns)->toBe(1);
});

it('keeps a sealed step it no longer owns exactly as it is and finishes nothing for it', function (): void {
    $step = doubleCheckStep(99, ['retries' => 1]);
    $rowAfterMove = null;

    // Recovery takes the row away after the work step and before the double
    // check is considered, which is where an obsolete attempt must stop first.
    DoubleCheckTestJob::$afterExecute = static function () use ($step, &$rowAfterMove): void {
        Carbon::setTestNow(now()->addDay());
        Artisan::call('steps:recover-stale');
        $rowAfterMove = (array) DB::table(Step::tableName())->where('id', $step->id)->first();
    };

    runDoubleCheck($step);

    expect($rowAfterMove)->not->toBeNull('the row was never taken away')
        ->and($rowAfterMove['state'])->toBe(Pending::class)
        ->and((array) DB::table(Step::tableName())->where('id', $step->id)->first())->toBe($rowAfterMove)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(0)
        ->and(DoubleCheckTestJob::$confirmRuns)->toBe(0)
        ->and(DoubleCheckTestJob::$completeRuns)->toBe(0)
        ->and(DoubleCheckTestJob::$finalizeRuns)->toBe(0);
});

it('leaves a sealed parent running while its children work and completes it by the parent rule', function (): void {
    $childBlock = (string) Str::uuid();

    $parent = doubleCheckStep(99, ['child_block_uuid' => $childBlock, 'retries' => 1]);

    $child = Step::create([
        'class' => ObsoleteAttemptPlainTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => $childBlock,
        'state' => Pending::class,
    ]);

    $afterRetry = runDoubleCheck($parent);

    expect($afterRetry->state)->toBeInstanceOf(Running::class)
        ->and((int) $afterRetry->double_check)->toBe(99)
        ->and($afterRetry->error_message)->toBeNull()
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(0)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$completeRuns)->toBe(1);

    DB::table(Step::tableName())->where('id', $child->id)->update(['state' => Completed::class]);

    StepDispatcher::transitionParentsToComplete('test-group');

    expect($parent->fresh()->state)->toBeInstanceOf(Completed::class)
        ->and((int) $parent->fresh()->double_check)->toBe(99);
});


/*
|--------------------------------------------------------------------------
| A sealed step is checked again
|--------------------------------------------------------------------------
*/

it('fails a sealed retry whose check no longer passes, keeps the seal and never completes it', function (): void {
    $step = doubleCheckStep(99, ['retries' => 1]);

    DoubleCheckTestJob::$pass = false;

    $fresh = runDoubleCheck($step);

    expect(sealedStepState($step))->toBe([
        'state' => Failed::class, 'double_check' => 99, 'retries' => 1,
        'compute' => 0, 'doubleCheck' => 1, 'complete' => 0,
    ])
        ->and($fresh->error_message)->toContain(SEALED_RECHECK_FAILED_MESSAGE)
        ->and($fresh->error_message)->not->toContain('verification budget exhausted')
        ->and(DoubleCheckTestJob::$confirmRuns)->toBe(0)
        ->and(DoubleCheckTestJob::$finalizeRuns)->toBe(0);
});

it('keeps the seal and runs the check again on every sealed attempt, not only the first', function (): void {
    $step = doubleCheckStep();

    DoubleCheckTestJob::$onComplete = static function ($job, int $run): void {
        if ($run <= 2) {
            $job->retryJob();
        }
    };

    $first = runDoubleCheck($step);
    $second = runDoubleCheck($first);

    expect($second->state)->toBeInstanceOf(Pending::class)
        ->and((int) $second->double_check)->toBe(99)
        ->and((int) $second->retries)->toBe(2)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(2);

    $third = runDoubleCheck($second);

    expect($third->state)->toBeInstanceOf(Completed::class)
        ->and((int) $third->double_check)->toBe(99)
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(3)
        ->and(DoubleCheckTestJob::$completeRuns)->toBe(3);
});

it('fails a step whose order disappears after its check passed, when a later attempt checks again', function (): void {
    $step = doubleCheckStep();

    DoubleCheckTestJob::$onComplete = static function ($job, int $run): void {
        if ($run === 1) {
            $job->retryJob();
        }
    };

    $afterFirst = runDoubleCheck($step);

    expect($afterFirst->state)->toBeInstanceOf(Pending::class)
        ->and((int) $afterFirst->double_check)->toBe(99);

    // Between the attempts the thing the check confirmed is gone.
    DoubleCheckTestJob::$pass = false;

    $final = runDoubleCheck($afterFirst);

    expect($final->state)->toBeInstanceOf(Failed::class)
        ->and((int) $final->double_check)->toBe(99)
        ->and($final->error_message)->toContain(SEALED_RECHECK_FAILED_MESSAGE)
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(2)
        ->and(DoubleCheckTestJob::$completeRuns)->toBe(1);
});

it('ends a sealed retry through the exception path when the second check throws', function (): void {
    $step = doubleCheckStep(99, ['retries' => 1]);

    DoubleCheckTestJob::$onDoubleCheck = static function (): void {
        throw new RuntimeException('check service is down');
    };

    $fresh = runDoubleCheck($step);

    expect(sealedStepState($step))->toBe([
        'state' => Failed::class, 'double_check' => 99, 'retries' => 1,
        'compute' => 0, 'doubleCheck' => 1, 'complete' => 0,
    ])
        ->and($fresh->error_message)->toContain('check service is down')
        ->and($fresh->error_message)->not->toContain(SEALED_RECHECK_FAILED_MESSAGE);
});

it('retries a sealed step through the exception path when the second check throws a retryable error', function (): void {
    $step = doubleCheckStep(99, ['retries' => 1]);

    DoubleCheckTestJob::$onDoubleCheck = static function (): void {
        throw new RuntimeException(DoubleCheckTestJob::RETRYABLE_MESSAGE);
    };

    $afterFirst = runDoubleCheck($step);

    expect(sealedStepState($step))->toBe([
        'state' => Pending::class, 'double_check' => 99, 'retries' => 2,
        'compute' => 0, 'doubleCheck' => 1, 'complete' => 0,
    ]);

    DoubleCheckTestJob::$onDoubleCheck = null;

    $final = runDoubleCheck($afterFirst);

    expect(sealedStepState($step))->toBe([
        'state' => Completed::class, 'double_check' => 99, 'retries' => 2,
        'compute' => 0, 'doubleCheck' => 2, 'complete' => 1,
    ]);
});

it('keeps the completed result of a sealed parent that the dispatcher completed while its check failed', function (): void {
    $childBlock = (string) Str::uuid();

    $parent = doubleCheckStep(99, ['child_block_uuid' => $childBlock, 'retries' => 1]);

    $child = Step::create([
        'class' => ObsoleteAttemptPlainTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => $childBlock,
        'state' => Pending::class,
    ]);

    // The children conclude and the dispatcher completes the parent while its
    // own attempt is still between the work step and the check.
    DoubleCheckTestJob::$afterExecute = static function () use ($child): void {
        DB::table(Step::tableName())->where('id', $child->id)->update(['state' => Completed::class]);
        StepDispatcher::transitionParentsToComplete('test-group');
    };

    DoubleCheckTestJob::$pass = false;

    $fresh = runDoubleCheck($parent);

    expect($fresh->state)->toBeInstanceOf(Completed::class)
        ->and((int) $fresh->double_check)->toBe(99)
        ->and($fresh->error_message)->toContain(SEALED_RECHECK_FAILED_MESSAGE)
        ->and(DoubleCheckTestJob::$doubleCheckRuns)->toBe(1)
        ->and(DoubleCheckTestJob::$completeRuns)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| An attempt that decided its own outcome is not checked
|--------------------------------------------------------------------------
|
| When compute() asks for its own retry or reschedule, or skips or stops the
| step, the attempt has already decided how it ends. Nothing is checked,
| sealed or incremented, and double_check stays as it was, so the next
| attempt runs compute() again.
|
*/

dataset('ways compute asks for another attempt', [
    'retryJob' => ['retryJob', 1, false],
    'rescheduleWithoutRetry' => ['rescheduleWithoutRetry', 0, true],
]);

dataset('check results', [
    'check would pass' => [true],
    'check would fail' => [false],
]);

it('runs compute again on the next attempt, without checking or sealing the one that asked for it', function (string $ask, int $retries, bool $throttled, bool $checkPasses): void {
    $step = doubleCheckStep();

    DoubleCheckTestJob::$pass = $checkPasses;
    DoubleCheckTestJob::$onCompute = static function ($job, int $run) use ($ask): void {
        if ($run === 1) {
            $job->{$ask}();
        }
    };

    $afterFirst = runDoubleCheck($step);

    expect(sealedStepState($step))->toBe([
        'state' => Pending::class, 'double_check' => 0, 'retries' => $retries,
        'compute' => 1, 'doubleCheck' => 0, 'complete' => 0,
    ])
        ->and((bool) $afterFirst->is_throttled)->toBe($throttled)
        ->and($afterFirst->error_message)->toBeNull();

    // The order is placed this time and the check confirms it.
    DoubleCheckTestJob::$pass = true;

    $final = runDoubleCheck($afterFirst);

    expect(sealedStepState($step))->toBe([
        'state' => Completed::class, 'double_check' => 99, 'retries' => $retries,
        'compute' => 2, 'doubleCheck' => 1, 'complete' => 1,
    ])
        ->and($final->error_message)->toBeNull()
        ->and(DoubleCheckTestJob::$finalizeRuns)->toBe(1);
})->with('ways compute asks for another attempt')->with('check results');

it('runs compute again when the first attempt asked for a retry and the second check then fails once', function (): void {
    $step = doubleCheckStep();

    DoubleCheckTestJob::$onCompute = static function ($job, int $run): void {
        if ($run === 1) {
            $job->retryJob();
        }
    };

    $afterFirst = runDoubleCheck($step);

    DoubleCheckTestJob::$pass = false;

    $afterSecond = runDoubleCheck($afterFirst);

    expect(sealedStepState($step))->toBe([
        'state' => Pending::class, 'double_check' => 1, 'retries' => 2,
        'compute' => 2, 'doubleCheck' => 1, 'complete' => 0,
    ]);

    DoubleCheckTestJob::$pass = true;

    runDoubleCheck($afterSecond);

    expect(sealedStepState($step))->toBe([
        'state' => Completed::class, 'double_check' => 99, 'retries' => 2,
        'compute' => 2, 'doubleCheck' => 2, 'complete' => 1,
    ]);
});

dataset('ways compute ends the step', [
    'skipJob' => ['skipJob', Skipped::class],
    'stopJob' => ['stopJob', Stopped::class],
]);

it('does not check, seal or complete a step that compute skipped or stopped', function (string $ask, string $state, bool $checkPasses): void {
    $step = doubleCheckStep();

    DoubleCheckTestJob::$pass = $checkPasses;
    DoubleCheckTestJob::$onCompute = static function ($job) use ($ask): void {
        $job->{$ask}();
    };

    $fresh = runDoubleCheck($step);

    expect(sealedStepState($step))->toBe([
        'state' => $state, 'double_check' => 0, 'retries' => 0,
        'compute' => 1, 'doubleCheck' => 0, 'complete' => 0,
    ])
        ->and($fresh->error_message)->toBeNull();
})->with('ways compute ends the step')->with('check results');
