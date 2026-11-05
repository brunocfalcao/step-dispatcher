<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Models\StepsDispatcherTicks;
use StepDispatcher\Tests\Support\StepTester;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;


// Schematic: Concurrent dispatch prevention
// When dispatcher is already running, second dispatch should be skipped
it('skips dispatch when lock is already held', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
    ], ScenarioTestJob::class);

    $group = $steps[0]->group;

    // Manually acquire lock
    expect(StepsDispatcher::startDispatch($group))->toBe(true);

    // Try to dispatch - should skip because lock is held
    StepDispatcher\Support\StepDispatcher::dispatch($group);

    // Step should still be pending (not dispatched)
    $steps[0]->refresh();
    expect($steps[0]->state->value())->toBe('pending');

    // Release lock
    StepsDispatcher::endDispatch(0, $group);

    // Now dispatch should work
    StepDispatcher\Support\StepDispatcher::dispatch($group);

    $steps[0]->refresh();
    expect($steps[0]->state->value())->toBe('completed');
});

// Schematic: A broken step does not hold the group
// A job class that does not exist fails that step and leaves the lock free
it('frees the lock after a step with a missing job class fails', function (): void {
    $block = (string) Str::uuid();

    $step = StepDispatcher\Models\Step::factory()->create([
        'block_uuid' => $block,
        'index' => 1,
        'class' => 'NonExistent\\Class\\That\\Will\\Fail',
        'queue' => 'sync',
    ]);

    $group = $step->group;

    StepDispatcher\Support\StepDispatcher::dispatch($group);

    expect($step->refresh()->state->value())->toBe('failed');

    // Lock should be released, so we should be able to acquire it
    expect(StepsDispatcher::startDispatch($group))->toBe(true);

    // Clean up
    StepsDispatcher::endDispatch(0, $group);
});

// Schematic: Lock release on exception
// A tick that throws midway still closes itself and frees the lock
it('frees the lock and closes the tick when a tick throws midway', function (): void {
    $step = StepTester::createSteps([
        ['block_uuid' => (string) Str::uuid(), 'index' => 1],
    ], ScenarioTestJob::class)[0];

    $group = $step->group;

    $thrown = false;
    Step::retrieved(static function () use (&$thrown): void {
        if (! $thrown) {
            $thrown = true;

            throw new RuntimeException('tick failure');
        }
    });

    expect(static fn () => StepDispatcher\Support\StepDispatcher::dispatch($group))
        ->toThrow(RuntimeException::class, 'tick failure');

    $lock = StepsDispatcher::query()->where('group', $group)->sole();
    $tick = StepsDispatcherTicks::query()->where('group', $group)->sole();

    expect($lock->can_dispatch)->toBeTrue()
        ->and($lock->current_tick_id)->toBeNull()
        ->and($tick->completed_at)->not->toBeNull()
        ->and($step->refresh()->state->value())->toBe('pending');
});

// Schematic: Multiple groups can run simultaneously
// Alpha and beta groups should not block each other
it('allows concurrent dispatch for different groups', function (): void {
    $blockA = (string) Str::uuid();
    $blockB = (string) Str::uuid();

    $stepsA = StepTester::createSteps([
        ['block_uuid' => $blockA, 'index' => 1],
    ], ScenarioTestJob::class);

    $stepsB = StepTester::createSteps([
        ['block_uuid' => $blockB, 'index' => 1],
    ], ScenarioTestJob::class);

    // Force different groups
    $stepsA[0]->update(['group' => 'alpha']);
    $stepsB[0]->update(['group' => 'beta']);

    // Acquire lock for alpha
    expect(StepsDispatcher::startDispatch('alpha'))->toBe(true);

    // Should still be able to acquire lock for beta
    expect(StepsDispatcher::startDispatch('beta'))->toBe(true);

    // Release locks
    StepsDispatcher::endDispatch(0, 'alpha');
    StepsDispatcher::endDispatch(0, 'beta');
});

// Schematic: Lock tracking per group
// Each group maintains its own lock state
it('tracks lock state independently per group', function (): void {
    // Acquire alpha lock
    expect(StepsDispatcher::startDispatch('alpha'))->toBe(true);

    // Cannot acquire alpha lock again
    expect(StepsDispatcher::startDispatch('alpha'))->toBe(false);

    // But can acquire beta lock
    expect(StepsDispatcher::startDispatch('beta'))->toBe(true);

    // Release alpha
    StepsDispatcher::endDispatch(0, 'alpha');

    // Now can acquire alpha again
    expect(StepsDispatcher::startDispatch('alpha'))->toBe(true);

    // Clean up
    StepsDispatcher::endDispatch(0, 'alpha');
    StepsDispatcher::endDispatch(0, 'beta');
});

// Schematic: Null group lock behavior
// Null group (global dispatch) should have its own lock
it('handles null group lock independently', function (): void {
    // Acquire null group lock
    expect(StepsDispatcher::startDispatch(null))->toBe(true);

    // Cannot acquire null group lock again
    expect(StepsDispatcher::startDispatch(null))->toBe(false);

    // But can acquire named group lock
    expect(StepsDispatcher::startDispatch('alpha'))->toBe(true);

    // Release locks
    StepsDispatcher::endDispatch(0, null);
    StepsDispatcher::endDispatch(0, 'alpha');
});

// Schematic: Progress tracking in lock
// endDispatch records how far each tick got on that tick's own row
it('tracks dispatch progress through lock lifecycle', function (): void {
    $group = 'progress-'.Str::uuid();

    expect(StepsDispatcher::startDispatch($group))->toBe(true);
    StepsDispatcher::endDispatch(3, $group);

    expect(StepsDispatcher::startDispatch($group))->toBe(true);
    StepsDispatcher::endDispatch(7, $group);

    $ticks = StepsDispatcherTicks::query()->where('group', $group)->orderBy('id')->get();

    expect($ticks->pluck('progress')->all())->toBe([3, 7])
        ->and($ticks->whereNull('completed_at'))->toHaveCount(0);
});

// Schematic: Idempotent dispatch
// Running dispatch multiple times for same group should be safe
it('handles idempotent dispatch calls safely', function (): void {
    $block = (string) Str::uuid();

    $step = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
    ], ScenarioTestJob::class)[0];

    $group = $step->group;

    // First dispatch - should complete step
    StepDispatcher\Support\StepDispatcher::dispatch($group);

    $step->refresh();
    expect($step->state->value())->toBe('completed');

    // Second dispatch - should be safe (no pending steps)
    StepDispatcher\Support\StepDispatcher::dispatch($group);

    $step->refresh();
    expect($step->state->value())->toBe('completed'); // Still completed

    // Third dispatch - still safe
    StepDispatcher\Support\StepDispatcher::dispatch($group);

    $step->refresh();
    expect($step->state->value())->toBe('completed');
});
