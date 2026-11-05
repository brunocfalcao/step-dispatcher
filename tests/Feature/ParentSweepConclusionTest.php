<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Running;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Concluding a parent
|--------------------------------------------------------------------------
|
| A parent's result stands once it is saved. When a project's reaction to it
| fails, the failure is logged, the reaction is not repeated, and the workflow
| carries on. Only a save that never happened leaves the parent running for a
| later tick. The order of events against a competing worker is covered by
| tests/Collision.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function seedSweepParent(string $childState): array
{
    $childBlock = (string) Str::uuid();

    $parent = Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'parent-sweep',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
        'child_block_uuid' => $childBlock,
    ]);

    $child = Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'parent-sweep',
        'index' => 1,
        'block_uuid' => $childBlock,
    ]);

    Step::withoutEvents(static function () use ($parent, $child, $childState): void {
        Step::where('id', $parent->id)->update(['state' => Running::class]);
        Step::where('id', $child->id)->update(['state' => $childState]);
    });

    return [$parent->fresh(), $child->fresh()];
}

/**
 * @param  Closure(): Throwable  $failure
 */
function failListenerFor(Step $parent, Closure $failure): void
{
    Step::updated(static function (Step $step) use ($parent, $failure): void {
        if ($step->id === $parent->id && $step->wasChanged('state')) {
            throw $failure();
        }
    });
}

dataset('parent sweeps', [
    'failure sweep' => ['transitionParentsToFailed', Failed::class, Failed::class],
    'stop sweep' => ['transitionParentsToStopped', Stopped::class, Stopped::class],
    'completion sweep' => ['transitionParentsToComplete', Completed::class, Completed::class],
]);

dataset('listener failures', [
    'an ordinary failure' => [static fn (): Throwable => new RuntimeException('listener failed')],
    'a programming error' => [static fn (): Throwable => new TypeError('listener is broken')],
]);

it('concludes a parent nothing competes for', function (string $sweep, string $childState, string $expected): void {
    [$parent] = seedSweepParent($childState);

    expect(StepDispatcher::{$sweep}('parent-sweep'))->toBeTrue()
        ->and($parent->fresh()->state)->toBeInstanceOf($expected)
        ->and($parent->fresh()->completed_at)->not->toBeNull();
})->with('parent sweeps');

it('shows listeners why a parent failed at the moment its state changes', function (string $sweep, string $childState, string $label): void {
    [$parent, $child] = seedSweepParent($childState);

    $seen = [];

    Step::updated(static function (Step $step) use ($parent, &$seen): void {
        if ($step->id === $parent->id && $step->wasChanged('state')) {
            $seen[] = $step->error_message;
        }
    });

    StepDispatcher::{$sweep}('parent-sweep');

    expect($seen)->toBe(["Child step(s) {$label}: [{$child->id}]"]);
})->with([
    'failure sweep' => ['transitionParentsToFailed', Failed::class, 'failed'],
    'stop sweep' => ['transitionParentsToStopped', Stopped::class, 'stopped'],
]);

it('keeps the result and does not repeat a reaction that fails', function (string $sweep, string $childState, string $expected, Closure $failure): void {
    [$parent] = seedSweepParent($childState);

    $reactions = 0;

    Step::updated(static function (Step $step) use ($parent, $failure, &$reactions): void {
        if ($step->id === $parent->id && $step->wasChanged('state')) {
            $reactions++;

            throw $failure();
        }
    });

    StepDispatcher::{$sweep}('parent-sweep');
    StepDispatcher::{$sweep}('parent-sweep');

    $row = $parent->fresh();

    expect($row->state)->toBeInstanceOf($expected)
        ->and($row->completed_at)->not->toBeNull()
        ->and($reactions)->toBe(1);
})->with('parent sweeps')->with('listener failures');

it('concludes the next parent when a reaction to the first fails', function (string $sweep, string $childState, string $expected, Closure $failure): void {
    [$first] = seedSweepParent($childState);
    [$next] = seedSweepParent($childState);
    failListenerFor($first, $failure);

    expect(StepDispatcher::{$sweep}('parent-sweep'))->toBeTrue()
        ->and($first->fresh()->state)->toBeInstanceOf($expected)
        ->and($next->fresh()->state)->toBeInstanceOf($expected);
})->with('parent sweeps')->with('listener failures');

it('leaves a parent running for a later tick when its save never happened', function (string $sweep, string $childState, string $expected): void {
    [$parent] = seedSweepParent($childState);

    $attempts = 0;

    Step::updating(static function (Step $step) use ($parent, &$attempts): void {
        if ($step->id === $parent->id && $step->isDirty('state') && ++$attempts === 1) {
            throw new RuntimeException('save refused once');
        }
    });

    expect(StepDispatcher::{$sweep}('parent-sweep'))->toBeFalse()
        ->and($parent->fresh()->state)->toBeInstanceOf(Running::class)
        ->and(StepDispatcher::{$sweep}('parent-sweep'))->toBeTrue()
        ->and($parent->fresh()->state)->toBeInstanceOf($expected)
        ->and($attempts)->toBe(2);
})->with('parent sweeps');

it('records a reaction that failed', function (string $sweep, string $childState): void {
    [$parent] = seedSweepParent($childState);
    failListenerFor($parent, static fn (): Throwable => new RuntimeException('listener failed'));

    Log::spy();

    StepDispatcher::{$sweep}('parent-sweep');

    Log::shouldHaveReceived('error')->withArgs(
        static fn (string $message, array $context): bool => $context['step_id'] === $parent->id
            && $context['exception'] === RuntimeException::class
            && $context['message'] === 'listener failed'
    )->once();
})->with([
    'failure sweep' => ['transitionParentsToFailed', Failed::class],
    'stop sweep' => ['transitionParentsToStopped', Stopped::class],
    'completion sweep' => ['transitionParentsToComplete', Completed::class],
]);

it('still dispatches other work when a reaction fails', function (string $sweep, string $childState, string $expected, Closure $failure): void {
    Queue::fake();

    [$parent] = seedSweepParent($childState);
    failListenerFor($parent, $failure);

    $runnable = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'parent-sweep',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ]);

    StepDispatcher::dispatch('parent-sweep');
    StepDispatcher::dispatch('parent-sweep');

    expect($parent->fresh()->state)->toBeInstanceOf($expected)
        ->and($runnable->fresh()->state)->toBeInstanceOf(Dispatched::class);
})->with('parent sweeps')->with('listener failures');

it('moves the workflow on past a parent whose reaction fails', function (string $childState, string $expectedParent, string $expectedNext): void {
    Queue::fake();

    [$parent] = seedSweepParent($childState);

    $next = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'parent-sweep',
        'index' => 2,
        'block_uuid' => $parent->block_uuid,
    ]);

    $reactions = 0;

    Step::updated(static function (Step $step) use ($parent, &$reactions): void {
        if ($step->id === $parent->id && $step->wasChanged('state')) {
            $reactions++;

            throw new RuntimeException('project reaction failed');
        }
    });

    foreach (range(1, 5) as $pass) {
        StepDispatcher::dispatch('parent-sweep');
    }

    expect($parent->fresh()->state)->toBeInstanceOf($expectedParent)
        ->and($next->fresh()->state)->toBeInstanceOf($expectedNext)
        ->and($reactions)->toBe(1);
})->with([
    'completed parent' => [Completed::class, Completed::class, Dispatched::class],
    'failed parent' => [Failed::class, Failed::class, Cancelled::class],
]);

it('keeps a cancel batch moving when a reaction raises a programming error', function (): void {
    [$first] = seedSweepParent(Completed::class);
    [$second] = seedSweepParent(Completed::class);

    Step::withoutEvents(static function () use ($first, $second): void {
        Step::whereIn('id', [$first->id, $second->id])->update(['state' => Dispatched::class]);
    });

    failListenerFor($first, static fn (): Throwable => new TypeError('listener is broken'));

    expect(StepDispatcher::batchTransitionSteps([$first->id, $second->id], Cancelled::class))->toBe(1)
        ->and($first->fresh()->state)->toBeInstanceOf(Cancelled::class)
        ->and($second->fresh()->state)->toBeInstanceOf(Cancelled::class);
});
