<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Models\StepsDispatcherTicks;
use StepDispatcher\Support\StepDispatcher;

/*
|--------------------------------------------------------------------------
| Tick Recording & Slow-Dispatch Callback (endDispatch)
|--------------------------------------------------------------------------
|
| Every tick opens a row in steps_dispatcher_ticks. To keep that table from
| exploding, a host app can register recordTickWhen() to discard
| uninteresting ticks (e.g. keep only slow ones). Separately, a tick slower
| than the configured threshold invokes the on_slow_dispatch callback so the
| host can alert. Both hooks live in endDispatch().
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

afterEach(function (): void {
    // Restore "record everything" (the default-null behaviour) so the
    // static recorder does not leak a discard rule into other test files.
    StepsDispatcher::recordTickWhen(fn () => true);
});

it('discards a tick when the recordTickWhen callable returns false', function (): void {
    StepsDispatcher::recordTickWhen(fn () => false);

    StepDispatcher::dispatch('rec-discard');

    expect(StepsDispatcherTicks::count())->toBe(0);
});

it('persists a tick when the recordTickWhen callable returns true', function (): void {
    StepsDispatcher::recordTickWhen(fn () => true);

    StepDispatcher::dispatch('rec-keep');

    expect(StepsDispatcherTicks::where('group', 'rec-keep')->exists())->toBeTrue();
});

it('invokes the slow-dispatch callback when a tick exceeds the threshold', function (): void {
    $observed = null;

    config()->set('step-dispatcher.dispatch.warning_threshold_ms', 0);
    config()->set('step-dispatcher.dispatch.on_slow_dispatch', function (int $ms) use (&$observed): void {
        $observed = $ms;
    });
    StepsDispatcher::recordTickWhen(fn () => true);

    StepsDispatcher::startDispatch('slow-group');

    // Backdate the tick-start marker so endDispatch computes a large duration.
    Cache::put('steps_dispatcher_tick_start:slow-group', microtime(true) - 5.0, 300);

    StepsDispatcher::endDispatch(8, 'slow-group');

    expect($observed)->not->toBeNull()
        ->and($observed)->toBeGreaterThan(0);
});
