<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Dispatch failure generation races
|--------------------------------------------------------------------------
|
| A queue push can fail after the step has been claimed. The failure handler
| must only record Failed for the same dispatch generation that it claimed;
| recovery or a newer dispatch may have already won the row.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    config()->set('step-dispatcher.queues.valid', ['default', 'sync']);
    StepDispatcher::setQueueResolver(null);
});

afterEach(function (): void {
    StepDispatcher::setQueueResolver(null);
});

function makeDispatchFailureRaceStep(string $queue = 'default'): Step
{
    return Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => 'dispatch-failure-race-'.uniqid(),
        'index' => 1,
        'type' => 'default',
        'queue' => $queue,
        'group' => 'test-group',
        'state' => Pending::class,
    ]);
}

it('does not clobber recovery that wins after the failure refresh', function (): void {
    $step = makeDispatchFailureRaceStep();
    $armed = false;
    $requeued = false;

    DB::listen(function (QueryExecuted $query) use (&$armed, &$requeued, $step): void {
        if (! $armed || $requeued || ! preg_match('/^\s*select\b/i', $query->sql)) {
            return;
        }

        if (! preg_match('/\bfrom\s+["`]?steps["`]?\b/i', $query->sql)
            || ! in_array($step->id, array_map('intval', $query->bindings), true)) {
            return;
        }

        $requeued = true;
        DB::table(Step::tableName())->where('id', $step->id)->update([
            'state' => Pending::class,
            'retries' => 1,
            'error_message' => 'recovery won after refresh',
        ]);
    });

    StepDispatcher::setQueueResolver(function (Step $resolving) use (&$armed): string {
        $armed = true;

        throw new RuntimeException('queue backend unavailable');
    });

    StepDispatcher::dispatch('test-group');

    $step->refresh();
    expect($requeued)->toBeTrue()
        ->and($step->state)->toBeInstanceOf(Pending::class)
        ->and((int) $step->retries)->toBe(1)
        ->and($step->error_message)->toBe('recovery won after refresh');
});

it('does not fail a same-state row from a newer dispatch generation', function (): void {
    $step = makeDispatchFailureRaceStep();
    $armed = false;
    $advanced = false;

    DB::listen(function (QueryExecuted $query) use (&$armed, &$advanced, $step): void {
        if (! $armed || $advanced || ! preg_match('/^\s*select\b/i', $query->sql)) {
            return;
        }

        if (! preg_match('/\bfrom\s+["`]?steps["`]?\b/i', $query->sql)
            || ! in_array($step->id, array_map('intval', $query->bindings), true)) {
            return;
        }

        $advanced = true;
        DB::table(Step::tableName())->where('id', $step->id)->update([
            'state' => Dispatched::class,
            'retries' => 1,
            'tick_id' => 999,
            'error_message' => 'new generation owns the row',
        ]);
    });

    StepDispatcher::setQueueResolver(function (Step $resolving) use (&$armed): string {
        $armed = true;

        throw new RuntimeException('old generation queue failure');
    });

    StepDispatcher::dispatch('test-group');

    $step->refresh();
    expect($advanced)->toBeTrue()
        ->and($step->state)->toBeInstanceOf(Dispatched::class)
        ->and((int) $step->retries)->toBe(1)
        ->and((int) $step->tick_id)->toBe(999)
        ->and($step->error_message)->toBe('new generation owns the row');
});

it('rejects a newer non-null generation when the original tick was null', function (): void {
    $step = makeDispatchFailureRaceStep();
    DB::table(Step::tableName())->where('id', $step->id)->update([
        'state' => Dispatched::class,
        'tick_id' => null,
    ]);
    $step->refresh();

    $armed = false;
    $advanced = false;

    DB::listen(function (QueryExecuted $query) use (&$armed, &$advanced, $step): void {
        if (! $armed || $advanced || ! preg_match('/^\s*select\b/i', $query->sql)) {
            return;
        }

        if (! preg_match('/\bfrom\s+["`]?steps["`]?\b/i', $query->sql)
            || ! in_array($step->id, array_map('intval', $query->bindings), true)) {
            return;
        }

        $advanced = true;
        DB::table(Step::tableName())->where('id', $step->id)->update([
            'state' => Dispatched::class,
            'retries' => 1,
            'tick_id' => 999,
            'error_message' => 'new generation owns the null-tick row',
        ]);
    });

    StepDispatcher::setQueueResolver(function (Step $resolving) use (&$armed): string {
        $armed = true;

        throw new RuntimeException('old null-tick queue failure');
    });

    (new StepDispatcher)->dispatchSingleStep($step);

    $step->refresh();
    expect($advanced)->toBeTrue()
        ->and($step->state)->toBeInstanceOf(Dispatched::class)
        ->and((int) $step->retries)->toBe(1)
        ->and((int) $step->tick_id)->toBe(999)
        ->and($step->error_message)->toBe('new generation owns the null-tick row');
});

it('completes a sync step without invoking the queue failure guard', function (): void {
    $resolverCalled = false;
    StepDispatcher::setQueueResolver(function (Step $resolving) use (&$resolverCalled): string {
        $resolverCalled = true;

        return 'default';
    });

    $step = makeDispatchFailureRaceStep('sync');
    StepDispatcher::dispatch('test-group');

    $step->refresh();
    expect($resolverCalled)->toBeFalse()
        ->and($step->state)->toBeInstanceOf(Completed::class)
        ->and($step->response)->toBe(['observed_prefix' => '']);
});
