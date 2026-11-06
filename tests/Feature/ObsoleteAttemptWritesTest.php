<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\ModelStates\Exceptions\TransitionNotFound;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Support\Steps;
use StepDispatcher\Tests\Fixtures\ObsoleteAttemptLockingTestJob;
use StepDispatcher\Tests\Fixtures\ObsoleteAttemptPlainTestJob;
use StepDispatcher\Tests\Fixtures\ObsoleteAttemptStartHooksTestJob;
use StepDispatcher\Tests\Fixtures\ObsoleteAttemptTestJob;

/*
|--------------------------------------------------------------------------
| Obsolete attempts
|--------------------------------------------------------------------------
|
| A worker can outlive its claim: recovery re-queues a step it believes is
| stalled while the old worker is still alive, or another process stops or
| cancels the step. When that worker finally finishes, every write it would
| make describes an attempt that no longer owns the row. These tests pin that
| such a worker changes nothing and runs no further consumer hook, and that an
| attempt which still owns its step behaves exactly as before.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    ObsoleteAttemptTestJob::reset();
    ObsoleteAttemptPlainTestJob::reset();
    ObsoleteAttemptLockingTestJob::reset();
    ObsoleteAttemptStartHooksTestJob::reset();
});

/**
 * @param  class-string  $class
 * @param  array<string, mixed>  $columns
 */
function obsoleteAttemptStep(string $class = ObsoleteAttemptTestJob::class, array $columns = []): Step
{
    $step = Step::create([
        'class' => $class,
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'obsolete-attempt-test',
        'state' => Pending::class,
    ]);

    if ($columns !== []) {
        DB::table(Step::tableName())->where('id', $step->id)->update($columns);
    }

    return $step->fresh();
}

/**
 * @param  class-string  $class
 */
function runObsoleteAttempt(Step $step, string $class = ObsoleteAttemptTestJob::class): void
{
    $job = new $class;
    $job->step = $step;
    $job->handle();
}

function obsoleteAttemptRow(int $id): array
{
    return (array) DB::table(Step::tableName())->where('id', $id)->first();
}

function recoverStaleStepUnderTheWorker(): void
{
    Carbon::setTestNow(now()->addDay());
    Artisan::call('steps:recover-stale');
}

dataset('row moves', [
    'recovered by the stale-step command' => [static function (int $id): void {
        recoverStaleStepUnderTheWorker();

        expect(Step::find($id)->state)->toBeInstanceOf(Pending::class)
            ->and(Step::find($id)->retries)->toBe(1);
    }],
    'recovered and the retry already running' => [static function (int $id): void {
        recoverStaleStepUnderTheWorker();
        Step::find($id)->state->transitionTo(Running::class);
    }],
    'recovered and the retry already finished' => [static function (int $id): void {
        recoverStaleStepUnderTheWorker();
        Step::find($id)->state->transitionTo(Running::class);
        Step::find($id)->state->transitionTo(Completed::class);
    }],
    'failed by recovery with its retries exhausted' => [static function (int $id): void {
        $step = Step::find($id);
        $step->update(['error_message' => 'Recovered by stale detector: retries exhausted']);
        $step->state->transitionTo(Failed::class);
    }],
    'stopped by another process' => [static function (int $id): void {
        Step::find($id)->state->transitionTo(Stopped::class);
    }],
    'cancelled by another process' => [static function (int $id): void {
        Step::query()->whereKey($id)->update(['state' => Cancelled::class]);
    }],
    'put back without using a retry and claimed again' => [static function (int $id): void {
        Carbon::setTestNow(now()->addDay());
        $step = Step::find($id);
        $step->update(['is_throttled' => true]);
        $step->state->transitionTo(Pending::class);
        Step::find($id)->state->transitionTo(Running::class);
    }],
]);

dataset('attempt endings', [
    'finishes with a result' => [[]],
    'finishes with no result' => [['result' => null]],
    'throws and fails' => [['act' => static function (): void {
        throw new RuntimeException('boom');
    }]],
    'throws and retries' => [['retries' => true, 'act' => static function (): void {
        throw new RuntimeException('boom');
    }]],
    'throws and ignores' => [['ignores' => true, 'act' => static function (): void {
        throw new RuntimeException('boom');
    }]],
    'asks for a retry' => [['act' => static fn ($job) => $job->retryJob()]],
    'asks for a reschedule' => [['act' => static fn ($job) => $job->rescheduleWithoutRetry()]],
    'stops itself' => [['act' => static fn ($job) => $job->stopJob()]],
    'skips itself' => [['act' => static fn ($job) => $job->skipJob()]],
    'reports its own failure' => [['act' => static fn ($job) => $job->reportAndFail(new RuntimeException('boom'))]],
    'asks for confirmation' => [['act' => static fn ($job) => $job->retryForConfirmation()]],
    'reloads its copy and finishes' => [['act' => static fn ($job) => $job->step->refresh()]],
    'reloads its copy and throws' => [['act' => static function ($job): void {
        $job->step->refresh();

        throw new RuntimeException('boom');
    }]],
    'loses the step during a passing double check' => [['moveDuring' => 'doubleCheck']],
    'loses the step during a failing double check' => [['moveDuring' => 'doubleCheck', 'doubleCheck' => false]],
    'loses the step during a failing confirmation' => [['moveDuring' => 'confirmOrRetry', 'confirms' => false]],
    'loses the step during a passing confirmation run' => [['moveDuring' => 'confirmOrRetry', 'columns' => ['execution_mode' => 'confirming-completion']]],
    'loses the step during a failing confirmation run' => [['moveDuring' => 'confirmOrRetry', 'confirms' => false, 'columns' => ['execution_mode' => 'confirming-completion']]],
    'loses the step during its completion hook' => [['moveDuring' => 'complete']],
    'loses the step while logging a failure' => [[
        'moveDuring' => 'onExceptionLogged',
        'act' => static function (): void {
            throw new RuntimeException('boom');
        },
    ]],
    'loses the step while resolving a failure' => [[
        'moveDuring' => 'resolveException',
        'act' => static function (): void {
            throw new RuntimeException('boom');
        },
    ]],
]);

it('changes nothing and runs no later hook when an attempt has lost its step', function (array $ending, Closure $move): void {
    ObsoleteAttemptTestJob::$move = $move;
    ObsoleteAttemptTestJob::$moveDuring = $ending['moveDuring'] ?? 'compute';
    ObsoleteAttemptTestJob::$act = $ending['act'] ?? null;
    ObsoleteAttemptTestJob::$doubleCheckPasses = $ending['doubleCheck'] ?? true;
    ObsoleteAttemptTestJob::$confirms = $ending['confirms'] ?? true;
    ObsoleteAttemptTestJob::$retriesException = $ending['retries'] ?? false;
    ObsoleteAttemptTestJob::$ignoresException = $ending['ignores'] ?? false;

    if (array_key_exists('result', $ending)) {
        ObsoleteAttemptTestJob::$result = $ending['result'];
    }

    $step = obsoleteAttemptStep(columns: $ending['columns'] ?? []);

    runObsoleteAttempt($step);

    expect(ObsoleteAttemptTestJob::$rowAfterMove)->not->toBeNull('the row was never taken away')
        ->and(obsoleteAttemptRow($step->id))->toBe(ObsoleteAttemptTestJob::$rowAfterMove)
        ->and(ObsoleteAttemptTestJob::$hooksAfterMove)->toBe($ending['hooksAfterMove'] ?? []);
})->with('attempt endings')->with('row moves');

it('changes nothing for a job with no hooks when it has lost its step', function (Closure $ending, Closure $move): void {
    $step = obsoleteAttemptStep(ObsoleteAttemptPlainTestJob::class);
    $rowAfterMove = null;

    ObsoleteAttemptPlainTestJob::$whileRunning = static function ($job) use ($ending, $move, $step, &$rowAfterMove): void {
        $move($step->id);
        $rowAfterMove = obsoleteAttemptRow($step->id);
        $ending($job);
    };

    runObsoleteAttempt($step, ObsoleteAttemptPlainTestJob::class);

    expect($rowAfterMove)->not->toBeNull()
        ->and(obsoleteAttemptRow($step->id))->toBe($rowAfterMove);
})->with([
    'finishes' => [static function (): void {}],
    'throws' => [static function (): void {
        throw new RuntimeException('boom');
    }],
])->with('row moves');

it('still runs a consumer\'s release blocks when an attempt has lost its step', function (Closure $ending, bool $reschedules, Closure $move): void {
    $step = obsoleteAttemptStep(ObsoleteAttemptLockingTestJob::class);
    $rowAfterMove = null;

    ObsoleteAttemptLockingTestJob::$reschedulesFailures = $reschedules;
    ObsoleteAttemptLockingTestJob::$whileRunning = static function ($job) use ($ending, $move, $step, &$rowAfterMove): void {
        $move($step->id);
        $rowAfterMove = obsoleteAttemptRow($step->id);
        $ending($job);
    };

    runObsoleteAttempt($step, ObsoleteAttemptLockingTestJob::class);

    expect(ObsoleteAttemptLockingTestJob::$lockHeld)->toBeFalse()
        ->and(obsoleteAttemptRow($step->id))->toBe($rowAfterMove);
})->with([
    'finishes' => [static function (): void {}, false],
    'throws' => [static function (): void {
        throw new RuntimeException('boom');
    }, false],
    'throws and reschedules inside its own transaction' => [static function (): void {
        throw new RuntimeException('boom');
    }, true],
])->with('row moves');

it('does not start the work when the step is lost during a start check', function (string $hook, bool $passes, Closure $move): void {
    ObsoleteAttemptStartHooksTestJob::$moveDuring = $hook;
    ObsoleteAttemptStartHooksTestJob::$passes = $passes;
    ObsoleteAttemptStartHooksTestJob::$move = $move;

    $step = obsoleteAttemptStep(ObsoleteAttemptStartHooksTestJob::class);

    runObsoleteAttempt($step, ObsoleteAttemptStartHooksTestJob::class);

    expect(ObsoleteAttemptStartHooksTestJob::$rowAfterMove)->not->toBeNull('the row was never taken away')
        ->and(obsoleteAttemptRow($step->id))->toBe(ObsoleteAttemptStartHooksTestJob::$rowAfterMove)
        ->and(ObsoleteAttemptStartHooksTestJob::$computed)->toBeFalse()
        ->and(ObsoleteAttemptStartHooksTestJob::$preWorkRan)->toBeFalse()
        ->and(ObsoleteAttemptStartHooksTestJob::$hooksAfterMove)->toBe([]);
})->with(['startOrStop', 'startOrFail', 'startOrSkip', 'startOrRetry'])->with([
    'the check passes' => [true],
    'the check refuses' => [false],
])->with('row moves');

it('stops writing when a step it moved itself is taken over', function (Closure $ownMove, Closure $takeover, bool $doubleCheckPasses): void {
    $step = obsoleteAttemptStep();
    $rowAfterTakeover = null;

    ObsoleteAttemptTestJob::$doubleCheckPasses = $doubleCheckPasses;
    ObsoleteAttemptTestJob::$act = static function ($job) use ($ownMove, $takeover, $step, &$rowAfterTakeover): void {
        $ownMove($job);
        $takeover($step->id);
        $rowAfterTakeover = obsoleteAttemptRow($step->id);
    };

    runObsoleteAttempt($step);

    expect($rowAfterTakeover)->not->toBeNull()
        ->and(obsoleteAttemptRow($step->id))->toBe($rowAfterTakeover)
        ->and(ObsoleteAttemptTestJob::$hooks)->toBe(['compute']);
})->with([
    'after asking for a retry' => [static fn ($job) => $job->retryJob()],
    'after asking for a reschedule' => [static fn ($job) => $job->rescheduleWithoutRetry()],
])->with([
    'claimed by a new attempt' => [static function (int $id): void {
        Step::find($id)->state->transitionTo(Running::class);
    }],
    'dispatched again' => [static function (int $id): void {
        Step::query()->whereKey($id)->update(['state' => Dispatched::class]);
    }],
    'cancelled by another process' => [static function (int $id): void {
        Step::query()->whereKey($id)->update(['state' => Cancelled::class]);
    }],
])->with([
    'with a passing double check' => [true],
    'with a failing double check' => [false],
]);

it('runs a consumer pre-work and the work when the step is kept through the start checks', function (): void {
    ObsoleteAttemptStartHooksTestJob::$moveDuring = 'no start check';

    $step = obsoleteAttemptStep(ObsoleteAttemptStartHooksTestJob::class);

    runObsoleteAttempt($step, ObsoleteAttemptStartHooksTestJob::class);

    expect(ObsoleteAttemptStartHooksTestJob::$preWorkRan)->toBeTrue()
        ->and(ObsoleteAttemptStartHooksTestJob::$computed)->toBeTrue()
        ->and(Step::find($step->id)->state)->toBeInstanceOf(Completed::class);
});

it('stops writing when a retry it asked for was claimed and rescheduled by a new attempt', function (bool $doubleCheckPasses): void {
    $step = obsoleteAttemptStep();
    $rowAfterTakeover = null;

    ObsoleteAttemptTestJob::$doubleCheckPasses = $doubleCheckPasses;
    ObsoleteAttemptTestJob::$act = static function ($job) use ($step, &$rowAfterTakeover): void {
        $job->retryJob();

        Step::find($step->id)->state->transitionTo(Running::class);
        $successor = Step::find($step->id);
        $successor->update(['is_throttled' => true]);
        $successor->state->transitionTo(Pending::class);

        $rowAfterTakeover = obsoleteAttemptRow($step->id);
    };

    runObsoleteAttempt($step);

    expect(Step::find($step->id)->retries)->toBe(1)
        ->and(obsoleteAttemptRow($step->id))->toBe($rowAfterTakeover)
        ->and(ObsoleteAttemptTestJob::$hooks)->toBe(['compute']);
})->with([
    'with a passing double check' => [true],
    'with a failing double check' => [false],
]);

it('refuses to write from a copy a consumer transaction rolled back and did not reload', function (): void {
    ObsoleteAttemptTestJob::$act = static function ($job): void {
        try {
            DB::transaction(static function () use ($job): void {
                $job->retryJob();

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            // The consumer carries on with a copy the database no longer agrees with.
        }
    };

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Running::class)
        ->and($fresh->retries)->toBe(0)
        ->and($fresh->response)->toBeNull();
});

it('keeps owning the step when a consumer rolled back its own move and reloaded', function (): void {
    ObsoleteAttemptTestJob::$act = static function ($job): void {
        try {
            DB::transaction(static function () use ($job): void {
                $job->retryJob();

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            $job->step->refresh();
        }
    };

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Running::class)
        ->and($fresh->retries)->toBe(0)
        ->and($fresh->response)->toBe(['from' => 'this attempt']);
});

it('reads and leaves the prefixed row when an attempt on a prefixed table has lost its step', function (): void {
    $this->artisan('steps:install', ['--prefix' => 'trading'])->assertSuccessful();

    $stepId = Steps::usingPrefix('trading', static fn (): int => obsoleteAttemptStep(ObsoleteAttemptPlainTestJob::class)->id);
    $rowAfterMove = null;

    ObsoleteAttemptPlainTestJob::$whileRunning = static function () use ($stepId, &$rowAfterMove): void {
        Step::find($stepId)->state->transitionTo(Stopped::class);
        $rowAfterMove = (array) DB::table('trading_steps')->where('id', $stepId)->first();
    };

    Steps::usingPrefix('trading', static function () use ($stepId): void {
        $job = new ObsoleteAttemptPlainTestJob;
        $job->stepPrefix = 'trading_';
        $job->step = Step::find($stepId);
        $job->handle();
    });

    expect($rowAfterMove['state'])->toBe(Stopped::class)
        ->and((array) DB::table('trading_steps')->where('id', $stepId)->first())->toBe($rowAfterMove);
});

it('lets the retry finish normally after the old worker gives up', function (): void {
    ObsoleteAttemptTestJob::$move = static fn () => recoverStaleStepUnderTheWorker();
    ObsoleteAttemptTestJob::$result = ['from' => 'old worker'];

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    ObsoleteAttemptTestJob::reset();
    ObsoleteAttemptTestJob::$result = ['from' => 'retry'];

    runObsoleteAttempt(Step::find($step->id));

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Completed::class)
        ->and($fresh->retries)->toBe(1)
        ->and($fresh->double_check)->toBe(99)
        ->and($fresh->response)->toBe(['from' => 'retry'])
        ->and(ObsoleteAttemptTestJob::$hooks)->toBe(['compute', 'doubleCheck', 'confirmOrRetry', 'complete']);
});

it('records in the log that a worker finished after losing its step', function (): void {
    Log::spy();

    ObsoleteAttemptTestJob::$move = static fn () => recoverStaleStepUnderTheWorker();

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message, array $context): bool => $message === 'Obsolete step attempt stopped writing'
            && $context['step_id'] === $step->id
            && $context['class'] === ObsoleteAttemptTestJob::class
            && $context['attempt_retries'] === 0)
        ->once();
});

it('completes a current attempt as before', function (): void {
    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Completed::class)
        ->and($fresh->retries)->toBe(0)
        ->and($fresh->double_check)->toBe(99)
        ->and($fresh->response)->toBe(['from' => 'this attempt'])
        ->and($fresh->completed_at)->not->toBeNull()
        ->and($fresh->error_message)->toBeNull()
        ->and(ObsoleteAttemptTestJob::$hooks)->toBe(['compute', 'doubleCheck', 'confirmOrRetry', 'complete']);
});

it('fails a current attempt as before', function (): void {
    ObsoleteAttemptTestJob::$act = static function (): void {
        throw new RuntimeException('boom');
    };

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and($fresh->retries)->toBe(0)
        ->and($fresh->error_message)->toContain('boom')
        ->and($fresh->error_stack_trace)->not->toBeNull()
        ->and(ObsoleteAttemptTestJob::$hooks)->toBe([
            'compute',
            'retryException',
            'externalRetryException',
            'ignoreException',
            'externalIgnoreException',
            'onExceptionLogged',
            'resolveException',
            'externalResolveException',
        ]);
});

it('retries a current attempt as before when its exception is retryable', function (): void {
    ObsoleteAttemptTestJob::$retriesException = true;
    ObsoleteAttemptTestJob::$act = static function (): void {
        throw new RuntimeException('boom');
    };

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and($fresh->retries)->toBe(1)
        ->and($fresh->started_at)->toBeNull()
        ->and($fresh->dispatch_after->isFuture())->toBeTrue()
        ->and($fresh->response)->toBeNull();
});

it('completes a current attempt as before when its exception is ignored', function (): void {
    ObsoleteAttemptTestJob::$ignoresException = true;
    ObsoleteAttemptTestJob::$act = static function (): void {
        throw new RuntimeException('boom');
    };

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    expect(Step::find($step->id)->state)->toBeInstanceOf(Completed::class);
});

it('keeps writing after a current attempt moved its own step', function (): void {
    ObsoleteAttemptTestJob::$act = static fn ($job) => $job->retryJob();

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and($fresh->retries)->toBe(1)
        ->and($fresh->response)->toBe(['from' => 'this attempt'])
        ->and($fresh->double_check)->toBe(0)
        ->and(ObsoleteAttemptTestJob::$hooks)->toBe(['compute']);
});

it('reschedules a current attempt without using a retry as before', function (): void {
    ObsoleteAttemptTestJob::$result = null;
    ObsoleteAttemptTestJob::$act = static fn ($job) => $job->rescheduleWithoutRetry();

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and($fresh->retries)->toBe(0)
        ->and($fresh->is_throttled)->toBeTrue()
        ->and($fresh->was_throttled)->toBeTrue();
});

it('retries a current attempt as before when its double check fails', function (): void {
    ObsoleteAttemptTestJob::$doubleCheckPasses = false;

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and($fresh->retries)->toBe(1)
        ->and($fresh->double_check)->toBe(1)
        ->and($fresh->response)->toBe(['from' => 'this attempt']);
});

it('fails a current attempt as before when its double check budget is spent', function (): void {
    $step = obsoleteAttemptStep(columns: ['double_check' => 2]);

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and($fresh->error_message)->toContain('verification budget exhausted');
});

it('completes a current confirmation run as before without computing again', function (): void {
    $step = obsoleteAttemptStep(columns: ['execution_mode' => 'confirming-completion']);

    runObsoleteAttempt($step);

    expect(Step::find($step->id)->state)->toBeInstanceOf(Completed::class)
        ->and(ObsoleteAttemptTestJob::$hooks)->toBe(['confirmOrRetry']);
});

it('sends a current attempt to its confirmation run as before', function (): void {
    ObsoleteAttemptTestJob::$confirms = false;

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and($fresh->execution_mode)->toBe('confirming-completion')
        ->and($fresh->retries)->toBe(1);
});

it('stops a current attempt as before', function (): void {
    ObsoleteAttemptTestJob::$act = static fn ($job) => $job->stopJob();

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    expect(Step::find($step->id)->state)->toBeInstanceOf(Stopped::class);
});

it('keeps a current attempt working after it reloads its copy of the step', function (): void {
    ObsoleteAttemptTestJob::$act = static fn ($job) => $job->step->refresh();

    $step = obsoleteAttemptStep();

    runObsoleteAttempt($step);

    expect(Step::find($step->id)->state)->toBeInstanceOf(Completed::class);
});

it('leaves a parent running as before while its children work', function (): void {
    $childBlock = (string) Str::uuid();

    ObsoleteAttemptTestJob::$act = static function ($job) use ($childBlock): void {
        Step::create([
            'class' => ObsoleteAttemptPlainTestJob::class,
            'block_uuid' => $childBlock,
            'index' => 1,
            'type' => 'default',
            'queue' => 'default',
            'group' => $job->step->group,
            'state' => Pending::class,
        ]);
    };

    $step = obsoleteAttemptStep(columns: ['child_block_uuid' => $childBlock]);

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Running::class)
        ->and($fresh->response)->toBe(['from' => 'this attempt'])
        ->and(ObsoleteAttemptTestJob::$hooks)->toContain('complete');
});

it('leaves a parent running as before when its job says it handled the step', function (): void {
    ObsoleteAttemptTestJob::$act = static function ($job): void {
        $job->stepStatusUpdated = true;
    };

    $step = obsoleteAttemptStep(columns: ['child_block_uuid' => (string) Str::uuid()]);

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Running::class)
        ->and($fresh->response)->toBe(['from' => 'this attempt'])
        ->and(ObsoleteAttemptTestJob::$hooks)->not->toContain('complete');
});

it('still stores the result when the dispatcher completes a parent under its own attempt', function (): void {
    $childBlock = (string) Str::uuid();

    $step = obsoleteAttemptStep(columns: ['child_block_uuid' => $childBlock]);

    Step::create([
        'class' => ObsoleteAttemptPlainTestJob::class,
        'block_uuid' => $childBlock,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'obsolete-attempt-test',
        'state' => Pending::class,
    ]);

    DB::table(Step::tableName())->where('block_uuid', $childBlock)->update(['state' => Completed::class]);

    ObsoleteAttemptTestJob::$move = static function (): void {
        StepDispatcher::transitionParentsToComplete('obsolete-attempt-test');
    };

    runObsoleteAttempt($step);

    $fresh = Step::find($step->id);

    expect(ObsoleteAttemptTestJob::$rowAfterMove['state'])->toBe(Completed::class)
        ->and($fresh->state)->toBeInstanceOf(Completed::class)
        ->and($fresh->response)->toBe(['from' => 'this attempt'])
        ->and(ObsoleteAttemptTestJob::$hooks)->toContain('complete');
});

/*
|--------------------------------------------------------------------------
| A parent the dispatcher completed while its own job was still running
|--------------------------------------------------------------------------
|
| The sweep completes a parent once its children conclude, even if the parent's
| own job has not returned yet. That attempt has not been replaced, so it still
| stores its result and runs its hooks. It no longer decides the outcome: the
| first result stands, and its later steps may already be running on it.
|
*/

/**
 * @param  array<string, mixed>  $columns
 */
function parentCompletedUnderItsOwnJob(array $columns = []): Step
{
    $childBlock = (string) Str::uuid();

    $parent = obsoleteAttemptStep(columns: array_merge(['child_block_uuid' => $childBlock], $columns));

    $child = Step::create([
        'class' => ObsoleteAttemptPlainTestJob::class,
        'block_uuid' => $childBlock,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'obsolete-attempt-test',
        'state' => Pending::class,
    ]);

    DB::table(Step::tableName())->where('id', $child->id)->update(['state' => Completed::class]);

    ObsoleteAttemptTestJob::$move = static function (): void {
        StepDispatcher::transitionParentsToComplete('obsolete-attempt-test');
    };

    return $parent;
}

function recordOutcomesOf(Step $parent): ArrayObject
{
    $outcomes = new ArrayObject;

    Step::updated(static function (Step $step) use ($parent, $outcomes): void {
        if ($step->id === $parent->id && $step->wasChanged('state') && ! $step->state instanceof Running) {
            $outcomes->append(get_class($step->state));
        }
    });

    return $outcomes;
}

dataset('decisions a parent job can still make', [
    'throws' => [static function (): void {
        ObsoleteAttemptTestJob::$act = static function (): void {
            throw new RuntimeException('late failure');
        };
    }],
    'throws and asks for a retry' => [static function (): void {
        ObsoleteAttemptTestJob::$retriesException = true;
        ObsoleteAttemptTestJob::$act = static function (): void {
            throw new RuntimeException('late failure');
        };
    }],
    'stops itself' => [static function (): void {
        ObsoleteAttemptTestJob::$act = static fn ($job) => $job->stopJob();
    }],
    'skips itself' => [static function (): void {
        ObsoleteAttemptTestJob::$act = static fn ($job) => $job->skipJob();
    }],
    'retries itself' => [static function (): void {
        ObsoleteAttemptTestJob::$act = static fn ($job) => $job->retryJob();
    }],
    'reschedules itself' => [static function (): void {
        ObsoleteAttemptTestJob::$act = static fn ($job) => $job->rescheduleWithoutRetry();
    }],
    'asks for a confirmation run' => [static function (): void {
        ObsoleteAttemptTestJob::$act = static fn ($job) => $job->retryForConfirmation();
    }],
    'fails its double check' => [static function (): void {
        ObsoleteAttemptTestJob::$doubleCheckPasses = false;
    }],
    'cannot confirm its result' => [static function (): void {
        ObsoleteAttemptTestJob::$confirms = false;
    }],
]);

it('keeps the completed result when the parent job decides otherwise afterwards', function (Closure $arrange): void {
    $arrange();

    $parent = parentCompletedUnderItsOwnJob();
    $outcomes = recordOutcomesOf($parent);

    runObsoleteAttempt($parent);

    $row = obsoleteAttemptRow($parent->id);
    $afterSweep = ObsoleteAttemptTestJob::$rowAfterMove;

    expect($afterSweep['state'])->toBe(Completed::class)
        ->and($outcomes->getArrayCopy())->toBe([Completed::class]);

    foreach (['state', 'retries', 'completed_at', 'dispatch_after', 'is_throttled', 'was_throttled', 'priority', 'execution_mode'] as $column) {
        expect($row[$column])->toBe($afterSweep[$column], "{$column} changed after the dispatcher completed the parent");
    }
})->with('decisions a parent job can still make');

it('keeps the completed result when a redelivered parent job has run out of double checks', function (): void {
    Log::spy();

    $parent = parentCompletedUnderItsOwnJob(['double_check' => 2]);
    $outcomes = recordOutcomesOf($parent);

    // A job out of double checks skips its work, so the sweep lands on the claim.
    Step::updated(static function (Step $step) use ($parent): void {
        if ($step->id === $parent->id && $step->wasChanged('state') && $step->state instanceof Running) {
            StepDispatcher::transitionParentsToComplete('obsolete-attempt-test');
        }
    });

    runObsoleteAttempt($parent);

    $fresh = Step::find($parent->id);

    expect($outcomes->getArrayCopy())->toBe([Completed::class])
        ->and($fresh->state)->toBeInstanceOf(Completed::class)
        ->and($fresh->error_message)->toContain('verification budget exhausted')
        ->and($fresh->duration)->not->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message): bool => $message === 'Parent step kept its completed result')
        ->once();
});

it('keeps a failure on record when a parent job fails outright after the dispatcher completed it', function (): void {
    Log::spy();

    ObsoleteAttemptTestJob::$act = static fn ($job) => $job->reportAndFail(new RuntimeException('late failure'));

    $parent = parentCompletedUnderItsOwnJob();
    $outcomes = recordOutcomesOf($parent);

    runObsoleteAttempt($parent);

    $fresh = Step::find($parent->id);

    expect($outcomes->getArrayCopy())->toBe([Completed::class])
        ->and($fresh->state)->toBeInstanceOf(Completed::class)
        ->and($fresh->error_message)->toContain('late failure')
        ->and($fresh->error_stack_trace)->not->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message): bool => $message === 'Parent step kept its completed result')
        ->once();
});

it('reports one completion when a parent job finishes normally after the dispatcher completed it', function (): void {
    $parent = parentCompletedUnderItsOwnJob();
    $outcomes = recordOutcomesOf($parent);

    runObsoleteAttempt($parent);

    $fresh = Step::find($parent->id);

    expect($outcomes->getArrayCopy())->toBe([Completed::class])
        ->and($fresh->state)->toBeInstanceOf(Completed::class)
        ->and($fresh->response)->toBe(['from' => 'this attempt'])
        ->and($fresh->duration)->not->toBeNull()
        ->and(ObsoleteAttemptTestJob::$hooks)->toContain('complete');
});

it('keeps the completed result when a parent job reloads its copy and then decides otherwise', function (): void {
    ObsoleteAttemptTestJob::$act = static function ($job): void {
        $job->step->refresh();
        $job->stopJob();
    };

    $parent = parentCompletedUnderItsOwnJob();
    $outcomes = recordOutcomesOf($parent);

    runObsoleteAttempt($parent);

    expect($outcomes->getArrayCopy())->toBe([Completed::class])
        ->and(Step::find($parent->id)->state)->toBeInstanceOf(Completed::class);
});

it('finishes cleanly when a parent job reloads its copy after the dispatcher completed it', function (): void {
    ObsoleteAttemptTestJob::$act = static fn ($job) => $job->step->refresh();

    $parent = parentCompletedUnderItsOwnJob();
    $outcomes = recordOutcomesOf($parent);

    runObsoleteAttempt($parent);

    $fresh = Step::find($parent->id);

    expect($outcomes->getArrayCopy())->toBe([Completed::class])
        ->and($fresh->state)->toBeInstanceOf(Completed::class)
        ->and($fresh->response)->toBe(['from' => 'this attempt'])
        ->and($fresh->error_message)->toBeNull()
        ->and(ObsoleteAttemptTestJob::$hooks)->toContain('complete');
});

it('reports one completion when a parent job ignores a failure after the dispatcher completed it', function (): void {
    ObsoleteAttemptTestJob::$ignoresException = true;
    ObsoleteAttemptTestJob::$act = static function (): void {
        throw new RuntimeException('ignored failure');
    };

    $parent = parentCompletedUnderItsOwnJob();
    $outcomes = recordOutcomesOf($parent);

    runObsoleteAttempt($parent);

    $fresh = Step::find($parent->id);

    expect($outcomes->getArrayCopy())->toBe([Completed::class])
        ->and($fresh->state)->toBeInstanceOf(Completed::class)
        ->and($fresh->duration)->not->toBeNull();
});

it('keeps a late failure on record when the parent stays completed', function (): void {
    Log::spy();

    ObsoleteAttemptTestJob::$act = static function (): void {
        throw new RuntimeException('late failure');
    };

    $parent = parentCompletedUnderItsOwnJob();

    runObsoleteAttempt($parent);

    $fresh = Step::find($parent->id);

    expect($fresh->state)->toBeInstanceOf(Completed::class)
        ->and($fresh->error_message)->toContain('late failure');

    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message, array $context): bool => $message === 'Parent step kept its completed result'
            && $context['step_id'] === $parent->id)
        ->once();
});

it('counts a failed double check and logs the kept result once', function (): void {
    Log::spy();

    ObsoleteAttemptTestJob::$doubleCheckPasses = false;

    $parent = parentCompletedUnderItsOwnJob();

    runObsoleteAttempt($parent);

    expect(Step::find($parent->id)->double_check)->toBe(1);

    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message): bool => $message === 'Parent step kept its completed result')
        ->once();
});

it('still refuses a decision after the attempt completed the parent itself', function (): void {
    $childBlock = (string) Str::uuid();

    $parent = obsoleteAttemptStep(columns: ['child_block_uuid' => $childBlock]);

    $child = Step::create([
        'class' => ObsoleteAttemptPlainTestJob::class,
        'block_uuid' => $childBlock,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'obsolete-attempt-test',
        'state' => Pending::class,
    ]);

    DB::table(Step::tableName())->where('id', $child->id)->update(['state' => Completed::class]);

    $job = new ObsoleteAttemptTestJob;
    $job->step = $parent;
    $job->handle();

    expect(Step::find($parent->id)->state)->toBeInstanceOf(Completed::class)
        ->and(static fn () => $job->stopJob())->toThrow(TransitionNotFound::class)
        ->and(Step::find($parent->id)->state)->toBeInstanceOf(Completed::class);
});

it('leaves a completion helper called outside a claimed attempt as it was', function (): void {
    $step = obsoleteAttemptStep(columns: ['state' => Completed::class]);

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;

    $complete = new ReflectionMethod($job, 'completeIfNotHandled');

    expect(static fn () => $complete->invoke($job))->toThrow(TransitionNotFound::class);
});

it('stays quiet when a parent job agrees with the completed result', function (): void {
    Log::spy();

    runObsoleteAttempt(parentCompletedUnderItsOwnJob());

    Log::shouldNotHaveReceived('warning', ['Parent step kept its completed result', Mockery::any()]);
});

it('still lets a parent job decide its outcome while the parent is running', function (Closure $act, string $outcome): void {
    $childBlock = (string) Str::uuid();

    $parent = obsoleteAttemptStep(columns: ['child_block_uuid' => $childBlock]);

    Step::create([
        'class' => ObsoleteAttemptPlainTestJob::class,
        'block_uuid' => $childBlock,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'obsolete-attempt-test',
        'state' => Pending::class,
    ]);

    ObsoleteAttemptTestJob::$act = $act;

    runObsoleteAttempt($parent);

    expect(Step::find($parent->id)->state)->toBeInstanceOf($outcome);
})->with([
    'throws' => [static function (): void {
        throw new RuntimeException('failure while running');
    }, Failed::class],
    'stops itself' => [static fn ($job) => $job->stopJob(), Stopped::class],
    'retries itself' => [static fn ($job) => $job->retryJob(), Pending::class],
]);

it('refuses the result of an older attempt on a parent a newer attempt completed', function (): void {
    $parent = obsoleteAttemptStep(columns: ['child_block_uuid' => (string) Str::uuid()]);

    ObsoleteAttemptTestJob::$move = static function (int $id): void {
        DB::table(Step::tableName())->where('id', $id)->update([
            'state' => Completed::class,
            'retries' => 1,
        ]);
    };

    runObsoleteAttempt($parent);

    $fresh = Step::find($parent->id);

    expect($fresh->state)->toBeInstanceOf(Completed::class)
        ->and($fresh->retries)->toBe(1)
        ->and($fresh->response)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| A project's reaction to a job's outcome fails
|--------------------------------------------------------------------------
|
| The row lock orders competing writers and adds nothing else. The outcome a job
| decided stays saved, as it did before the lock existed, and the attempt is not
| mistaken for one that lost its step.
|
*/

/**
 * @param  class-string  $reactTo
 */
function runAttemptWithFailingReaction(Step $step, string $reactTo): void
{
    Step::updated(static function (Step $row) use ($step, $reactTo): void {
        if ($row->id === $step->id && $row->wasChanged('state') && $row->state instanceof $reactTo) {
            throw new RuntimeException('project reaction failed');
        }
    });

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;

    try {
        $job->handle();
    } catch (Throwable $e) {
        expect($e->getMessage())->toBe('project reaction failed');

        // What the queue does with a job whose handle() threw.
        try {
            $job->failed($e);
        } catch (Throwable) {
        }
    }
}

it('keeps the outcome a job decided when the reaction to it fails', function (string $outcome, int $retries, ?Closure $act): void {
    Log::spy();

    ObsoleteAttemptTestJob::$act = $act;

    $step = obsoleteAttemptStep();

    runAttemptWithFailingReaction($step, $outcome);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf($outcome)
        ->and($fresh->retries)->toBe($retries);

    Log::shouldNotHaveReceived('warning', ['Obsolete step attempt stopped writing', Mockery::any()]);
})->with([
    'a job that finishes' => [Completed::class, 0, null],
    'a job that throws' => [Failed::class, 0, static function (): void {
        throw new RuntimeException('job failed');
    }],
    'a job that stops itself' => [Stopped::class, 0, static fn ($job) => $job->stopJob()],
    'a job that retries itself' => [Pending::class, 1, static fn ($job) => $job->retryJob()],
]);

it('keeps a retry usable when the reaction to it fails', function (): void {
    ObsoleteAttemptTestJob::$act = static fn ($job) => $job->retryJob();

    $step = obsoleteAttemptStep();

    runAttemptWithFailingReaction($step, Pending::class);

    expect(Step::find($step->id)->state)->toBeInstanceOf(Pending::class)
        ->and(Step::find($step->id)->dispatch_after)->not->toBeNull();

    ObsoleteAttemptTestJob::reset();

    runObsoleteAttempt(Step::find($step->id));

    expect(Step::find($step->id)->state)->toBeInstanceOf(Completed::class)
        ->and(Step::find($step->id)->response)->toBe(['from' => 'this attempt']);
});

it('runs a failing reaction once in a job run', function (string $outcome, ?Closure $act): void {
    ObsoleteAttemptTestJob::$act = $act;

    $step = obsoleteAttemptStep();
    $fired = new ArrayObject;

    Step::updated(static function (Step $row) use ($step, $outcome, $fired): void {
        if ($row->id === $step->id && $row->wasChanged('state') && $row->state instanceof $outcome) {
            $fired->append(true);
        }
    });

    runAttemptWithFailingReaction($step, $outcome);

    expect($fired)->toHaveCount(1)
        ->and(Step::find($step->id)->state)->toBeInstanceOf($outcome)
        ->and(Step::find($step->id)->error_message)->not->toBeNull();
})->with([
    'a job that finishes' => [Completed::class, null],
    'a job that throws' => [Failed::class, static function (): void {
        throw new RuntimeException('job failed');
    }],
    'a job that stops itself' => [Stopped::class, static fn ($job) => $job->stopJob()],
    'a job that retries itself' => [Pending::class, static fn ($job) => $job->retryJob()],
]);

it('does not run the failure hooks of a job whose outcome was saved before its reaction failed', function (): void {
    $step = obsoleteAttemptStep();

    runAttemptWithFailingReaction($step, Completed::class);

    expect(ObsoleteAttemptTestJob::$hooks)
        ->not->toContain('onExceptionLogged')
        ->not->toContain('resolveException');
});

it('ends a step Failed and does not write a state whose save was refused', function (string $refused, ?Closure $act, bool $repeats): void {
    ObsoleteAttemptTestJob::$act = $act;

    $step = obsoleteAttemptStep();
    $refusals = 0;

    Step::updating(static function (Step $row) use ($step, $refused, $repeats, &$refusals): void {
        if ($row->id === $step->id && $row->state instanceof $refused && ($repeats || $refusals === 0)) {
            $refusals++;

            throw new RuntimeException('save refused');
        }
    });

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;
    $job->handle();

    $row = Step::find($step->id);

    expect($refusals)->toBe(1)
        ->and($row->state)->toBeInstanceOf(Failed::class)
        ->and($row->retries)->toBe(0)
        ->and($row->error_message)->toContain('save refused');
})->with([
    'a finish refused once' => [Completed::class, null, false],
    'a stop refused once' => [Stopped::class, static fn ($job) => $job->stopJob(), false],
    'a retry refused once' => [Pending::class, static fn ($job) => $job->retryJob(), false],
    'a finish always refused' => [Completed::class, null, true],
    'a stop always refused' => [Stopped::class, static fn ($job) => $job->stopJob(), true],
    'a retry always refused' => [Pending::class, static fn ($job) => $job->retryJob(), true],
]);

it('ends a step Failed when a save before its state change was refused', function (): void {
    ObsoleteAttemptTestJob::$act = static fn ($job) => $job->retryJob();

    $step = obsoleteAttemptStep();
    $refusals = 0;

    Step::updating(static function (Step $row) use ($step, &$refusals): void {
        if ($row->id === $step->id && $row->isDirty('dispatch_after') && $refusals === 0) {
            $refusals++;

            throw new RuntimeException('save refused');
        }
    });

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;
    $job->handle();

    $row = Step::find($step->id);

    expect($refusals)->toBe(1)
        ->and($row->state)->toBeInstanceOf(Failed::class)
        ->and($row->retries)->toBe(0)
        ->and($row->error_message)->toContain('save refused');
});

it('keeps the usual failure path when a later write fails after the job moved its step', function (): void {
    $step = obsoleteAttemptStep();
    $refuse = false;

    ObsoleteAttemptTestJob::$act = static function ($job) use (&$refuse): void {
        $job->retryJob(now()->addMinutes(1));

        $refuse = true;

        $job->retryJob(now()->addMinutes(2));
    };

    Step::updating(static function (Step $row) use ($step, &$refuse): void {
        if ($refuse && $row->id === $step->id) {
            $refuse = false;

            throw new RuntimeException('later write refused');
        }
    });

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;
    $job->handle();

    $row = Step::find($step->id);

    expect($row->state)->toBeInstanceOf(Pending::class)
        ->and($row->retries)->toBe(1)
        ->and($row->error_message)->toContain('later write refused')
        ->and(ObsoleteAttemptTestJob::$hooks)->toContain('onExceptionLogged', 'resolveException');
});

it('ends a step Failed when the transaction of a project undoes the state its job saved', function (?string $reactTo, Closure $move): void {
    $work = static fn ($job) => DB::transaction(static function () use ($job, $move, $reactTo): void {
        $move($job);

        if ($reactTo === null) {
            throw new RuntimeException('project work failed');
        }
    });

    ObsoleteAttemptTestJob::$act = $work;

    $step = obsoleteAttemptStep();
    $fired = 0;

    Step::updated(static function (Step $row) use ($step, $reactTo, &$fired): void {
        if ($reactTo !== null && $row->id === $step->id && $row->wasChanged('state') && $row->state instanceof $reactTo) {
            $fired++;

            throw new RuntimeException('project reaction failed');
        }
    });

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;
    $job->handle();

    $row = Step::find($step->id);

    expect($row->state)->toBeInstanceOf(Failed::class)
        ->and($row->retries)->toBe(0)
        ->and($row->error_message)->toContain($reactTo === null ? 'project work failed' : 'project reaction failed')
        ->and($fired)->toBe($reactTo === null ? 0 : 1)
        ->and(ObsoleteAttemptTestJob::$hooks)->toContain('onExceptionLogged', 'resolveException');
})->with([
    'a stop whose reaction failed' => [Stopped::class, static fn ($job) => $job->stopJob()],
    'a retry whose reaction failed' => [Pending::class, static fn ($job) => $job->retryJob()],
    'a stop followed by a failure' => [null, static fn ($job) => $job->stopJob()],
    'a retry followed by a failure' => [null, static fn ($job) => $job->retryJob()],
]);

it('runs the failure hooks when a refused write saved nothing on a parent the dispatcher completed', function (): void {
    $childBlock = (string) Str::uuid();
    $step = obsoleteAttemptStep(columns: ['child_block_uuid' => $childBlock]);

    obsoleteAttemptStep(ObsoleteAttemptPlainTestJob::class, ['block_uuid' => $childBlock, 'state' => Completed::class]);

    ObsoleteAttemptTestJob::$act = static function ($job): void {
        StepDispatcher::transitionParentsToComplete($job->step->group);

        $job->step->refresh();
    };

    Step::updating(static function (Step $row) use ($step): void {
        if ($row->id === $step->id && $row->isDirty('response')) {
            throw new RuntimeException('response refused');
        }
    });

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;
    $job->handle();

    $row = Step::find($step->id);

    expect($row->state)->toBeInstanceOf(Completed::class)
        ->and($row->error_message)->toContain('response refused')
        ->and(ObsoleteAttemptTestJob::$hooks)->toContain('onExceptionLogged', 'resolveException');
});

it('reports a failing reaction when the step moved on before the failure was handled', function (string $state, bool $newAttempt): void {
    ObsoleteAttemptTestJob::$act = static fn ($job) => $job->retryJob();

    $step = obsoleteAttemptStep();
    $armed = false;

    Step::updated(static function (Step $row) use ($step, &$armed): void {
        if ($row->id === $step->id && $row->wasChanged('state') && $row->state instanceof Pending) {
            $armed = true;

            throw new RuntimeException('project reaction failed');
        }
    });

    // The first read after the reaction is the one that settles the job's copy.
    // The row moves on right behind it, as a dispatcher tick would move it.
    Step::retrieved(static function (Step $row) use ($step, $state, $newAttempt, &$armed): void {
        if ($armed && $row->id === $step->id) {
            $armed = false;

            DB::table(Step::tableName())->where('id', $step->id)->update([
                'state' => $state,
                'started_at' => $newAttempt ? now()->addMinute() : null,
            ]);
        }
    });

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;

    expect(static fn () => $job->handle())->toThrow(RuntimeException::class, 'project reaction failed')
        ->and(Step::find($step->id)->state)->toBeInstanceOf($state);
})->with([
    'Dispatched' => [Dispatched::class, false],
    'Running under a new attempt' => [Running::class, true],
]);

it('reports a failing reaction when the row cannot be read back while the failure is handled', function (): void {
    ObsoleteAttemptTestJob::$act = static fn ($job) => $job->stopJob();

    $step = obsoleteAttemptStep();
    $reacted = false;
    $reads = 0;

    Step::updated(static function (Step $row) use ($step, &$reacted): void {
        if ($row->id === $step->id && $row->wasChanged('state') && $row->state instanceof Stopped) {
            $reacted = true;

            throw new RuntimeException('project reaction failed');
        }
    });

    // The first read after the reaction settles the job's copy, and only the
    // reads after it fail.
    Step::retrieved(static function (Step $row) use ($step, &$reacted, &$reads): void {
        if ($reacted && $row->id === $step->id && ++$reads > 1) {
            throw new RuntimeException('read failed');
        }
    });

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;

    expect(static fn () => $job->handle())->toThrow(RuntimeException::class, 'project reaction failed');
});

it('keeps a parent Completed when the dispatcher completes it after a project transaction undid its stop', function (): void {
    $step = obsoleteAttemptStep(columns: ['child_block_uuid' => (string) Str::uuid()]);

    ObsoleteAttemptTestJob::$act = static fn ($job) => DB::transaction(static function () use ($job): void {
        $job->stopJob();

        throw new RuntimeException('project work failed');
    });

    ObsoleteAttemptTestJob::$moveDuring = 'onExceptionLogged';
    ObsoleteAttemptTestJob::$move = static fn (int $id) => DB::table(Step::tableName())
        ->where('id', $id)
        ->update(['state' => Completed::class]);

    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;
    $job->handle();

    $row = Step::find($step->id);

    expect($row->state)->toBeInstanceOf(Completed::class)
        ->and($row->error_message)->toContain('project work failed');
});

it('reports the original failure when the row cannot be read back afterwards', function (): void {
    $reported = null;

    ObsoleteAttemptTestJob::$act = static function ($job) use (&$reported): void {
        try {
            $job->stopJob();
        } catch (Throwable $e) {
            $reported = $e->getMessage();
        }
    };

    $step = obsoleteAttemptStep();
    $refused = false;

    Step::updating(static function (Step $row) use ($step, &$refused): void {
        if ($row->id === $step->id && $row->state instanceof Stopped) {
            $refused = true;

            throw new RuntimeException('save refused');
        }
    });

    // What a statement inside a transaction Postgres already aborted does.
    Step::retrieved(static function () use (&$refused): void {
        if ($refused) {
            $refused = false;

            throw new RuntimeException('current transaction is aborted');
        }
    });

    try {
        runObsoleteAttempt($step);
    } catch (Throwable) {
        // The attempt goes on to fail in its own way; only the first report matters here.
    }

    expect($reported)->toBe('save refused');
});

it('reschedules a current attempt inside a consumer transaction as before', function (): void {
    ObsoleteAttemptLockingTestJob::$reschedulesFailures = true;
    ObsoleteAttemptLockingTestJob::$whileRunning = static function (): void {
        throw new RuntimeException('boom');
    };

    $step = obsoleteAttemptStep(ObsoleteAttemptLockingTestJob::class);

    runObsoleteAttempt($step, ObsoleteAttemptLockingTestJob::class);

    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and($fresh->retries)->toBe(0)
        ->and($fresh->is_throttled)->toBeTrue()
        ->and(ObsoleteAttemptLockingTestJob::$lockHeld)->toBeFalse();
});

it('completes a current attempt of a locking consumer as before', function (): void {
    ObsoleteAttemptLockingTestJob::$whileRunning = static function (): void {};

    $step = obsoleteAttemptStep(ObsoleteAttemptLockingTestJob::class);

    runObsoleteAttempt($step, ObsoleteAttemptLockingTestJob::class);

    expect(Step::find($step->id)->state)->toBeInstanceOf(Completed::class)
        ->and(ObsoleteAttemptLockingTestJob::$lockHeld)->toBeFalse();
});
