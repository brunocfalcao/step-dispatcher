<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

it('does not reopen a stale snapshot after the real worker completes it', function (): void {
    $this->travelTo(now()->startOfSecond());

    $step = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'running-race-test',
        'state' => Pending::class,
    ]);

    $startedAt = now()->subMinutes(10);

    Step::withoutEvents(static function () use ($step, $startedAt): void {
        Step::whereKey($step->id)->update([
            'state' => Running::class,
            'started_at' => $startedAt,
            'retries' => 0,
        ]);
    });

    $beforeRecovery = $step->fresh();

    expect($beforeRecovery->state)->toBeInstanceOf(Running::class)
        ->and($beforeRecovery->started_at)->toEqual($startedAt)
        ->and($beforeRecovery->retries)->toBe(0)
        ->and($beforeRecovery->completed_at)->toBeNull();

    $raceFired = false;
    $workerCompletedAt = now();

    Step::retrieved(function (Step $retrieved) use ($step, &$raceFired, $workerCompletedAt): void {
        if ($raceFired || $retrieved->id !== $step->id) {
            return;
        }

        $raceFired = true;

        DB::table('steps')->where('id', $step->id)->update([
            'state' => Completed::class,
            'completed_at' => $workerCompletedAt,
            'updated_at' => $workerCompletedAt,
        ]);
    });

    Artisan::call('steps:recover-stale');

    $fresh = $step->fresh();

    expect($raceFired)->toBeTrue()
        ->and($fresh->state)->toBeInstanceOf(Completed::class)
        ->and($fresh->retries)->toBe(0)
        ->and($fresh->started_at)->toEqual($startedAt)
        ->and($fresh->completed_at)->toEqual($workerCompletedAt);
});

it('does not requeue a stale parent whose child appears after the initial tree scan', function (): void {
    $childBlockUuid = 'running-race-child-'.Str::uuid();

    $parent = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => (string) Str::uuid(),
        'child_block_uuid' => $childBlockUuid,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'running-race-test',
        'state' => Pending::class,
    ]);

    Step::withoutEvents(static function () use ($parent): void {
        Step::whereKey($parent->id)->update([
            'state' => Running::class,
            'started_at' => now()->subMinutes(10),
            'retries' => 0,
        ]);
    });

    $raceFired = false;

    DB::listen(function (QueryExecuted $query) use ($childBlockUuid, &$raceFired): void {
        if ($raceFired
            || ! str_contains($query->sql, 'select distinct')
            || ! str_contains($query->sql, 'block_uuid')) {
            return;
        }

        $raceFired = true;

        Step::withoutEvents(static function () use ($childBlockUuid): void {
            Step::create([
                'class' => PrefixCarryingTestJob::class,
                'block_uuid' => $childBlockUuid,
                'index' => 1,
                'type' => 'default',
                'queue' => 'default',
                'group' => 'running-race-test',
                'state' => Pending::class,
            ]);
        });
    });

    Artisan::call('steps:recover-stale');

    $fresh = $parent->fresh();

    expect($raceFired)->toBeTrue()
        ->and(Step::where('block_uuid', $childBlockUuid)->exists())->toBeTrue()
        ->and($fresh->state)->toBeInstanceOf(Running::class)
        ->and($fresh->retries)->toBe(0);
});
