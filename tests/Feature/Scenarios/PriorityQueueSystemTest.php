<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Skipped;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;
use StepDispatcher\Tests\Support\StepTester;

// ========================================================================
// PRIORITY ESCALATION TESTS
// ========================================================================

it('escalates step to high priority when retries reach 50% threshold', function (): void {
    $group = 'test-priority-escalation';

    // Create step with retries already at 5 (50% of max 10)
    $step = Step::create([
        'block_uuid' => (string) Str::uuid(),
        'type' => 'default',
        'group' => $group,
        'state' => Pending::class,
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
        'retries' => 5, // At 50% threshold
        'priority' => 'default',
        'arguments' => [
            'should_start_or_retry' => false, // Force retry
        ],
    ]);

    // Create dispatcher
    StepsDispatcher::query()->forceCreate(['group' => $group, 'can_dispatch' => true]);

    // Dispatch once - should trigger retryJob() which should escalate priority
    StepDispatcher::dispatch($group);

    $step->refresh();

    // Should be escalated to high priority
    expect($step->priority)->toBe('high')
        ->and($step->state)->toBeInstanceOf(Pending::class);
});

it('does not escalate step to high priority when retries below 50% threshold', function (): void {
    $block = (string) Str::uuid();

    // Create step with retries at 3 (30% of max 10)
    $step = Step::create([
        'block_uuid' => $block,
        'type' => 'default',
        'state' => Pending::class,
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
        'retries' => 3, // Below 50% threshold
        'priority' => 'default',
        'arguments' => [
            'should_start_or_retry' => false, // Force retry
        ],
    ]);

    StepsDispatcher::query()->forceCreate(['group' => $block, 'can_dispatch' => true]);

    StepDispatcher::dispatch($block);

    $step->refresh();

    // Should remain at default priority
    expect($step->priority)->toBe('default');
});

it('preserves high priority on subsequent retries', function (): void {
    $block = (string) Str::uuid();

    // Create step already at high priority
    $step = Step::create([
        'block_uuid' => $block,
        'type' => 'default',
        'state' => Pending::class,
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
        'retries' => 2, // Below threshold
        'priority' => 'high', // Already high
        'arguments' => [
            'should_start_or_retry' => false, // Force retry
        ],
    ]);

    StepsDispatcher::query()->forceCreate(['group' => $block, 'can_dispatch' => true]);

    StepDispatcher::dispatch($block);

    $step->refresh();

    // Should maintain high priority even though retries is below threshold
    expect($step->priority)->toBe('high');
});

// ========================================================================
// PRIORITY FILTERING TESTS
// ========================================================================

it('dispatches only high-priority steps when mixed priorities exist', function (): void {
    $group = 'test-mixed-priorities';

    // Create 3 default priority steps (no index - independent)
    $defaultSteps = [];
    for ($i = 1; $i <= 3; $i++) {
        $defaultSteps[] = Step::create([
            'block_uuid' => (string) Str::uuid(),
            'type' => 'default',
            'group' => $group,
            'state' => Pending::class,
            'class' => ScenarioTestJob::class,
            'queue' => 'sync',
            'priority' => 'default',
        ]);
    }

    // Create 2 high-priority steps (no index - independent)
    $highSteps = [];
    for ($i = 1; $i <= 2; $i++) {
        $highSteps[] = Step::create([
            'block_uuid' => (string) Str::uuid(),
            'type' => 'default',
            'group' => $group,
            'state' => Pending::class,
            'class' => ScenarioTestJob::class,
            'queue' => 'sync',
            'priority' => 'high',
        ]);
    }

    StepsDispatcher::query()->forceCreate(['group' => $group, 'can_dispatch' => true]);

    // Dispatch - should only dispatch high-priority steps
    StepDispatcher::dispatch($group);

    // Refresh all steps
    foreach ($defaultSteps as $step) {
        $step->refresh();
    }
    foreach ($highSteps as $step) {
        $step->refresh();
    }

    // High-priority steps should be completed
    expect($highSteps[0]->state)->toBeInstanceOf(Completed::class)
        ->and($highSteps[1]->state)->toBeInstanceOf(Completed::class);

    // Default priority steps should still be pending
    expect($defaultSteps[0]->state)->toBeInstanceOf(Pending::class)
        ->and($defaultSteps[1]->state)->toBeInstanceOf(Pending::class)
        ->and($defaultSteps[2]->state)->toBeInstanceOf(Pending::class);
});

it('dispatches default priority steps when no high-priority steps exist', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'priority' => 'default'],
        ['block_uuid' => $block, 'index' => 2, 'priority' => 'default'],
    ], ScenarioTestJob::class);

    [$step1, $step2] = $steps;

    $statusMatrix = [
        1 => [$step1->id => 'completed', $step2->id => 'pending'],
        2 => [$step1->id => 'completed', $step2->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('default_priority_only')
        ->test();
});

it('processes high-priority steps first across multiple dispatch cycles', function (): void {
    $group = 'test-multi-dispatch';

    // Create 2 default priority steps (no index - independent)
    $step1 = Step::create([
        'block_uuid' => (string) Str::uuid(),
        'type' => 'default',
        'group' => $group,
        'state' => Pending::class,
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
        'priority' => 'default',
        'arguments' => ['custom_result' => ['executed' => 'step1']],
    ]);

    $step2 = Step::create([
        'block_uuid' => (string) Str::uuid(),
        'type' => 'default',
        'group' => $group,
        'state' => Pending::class,
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
        'priority' => 'default',
        'arguments' => ['custom_result' => ['executed' => 'step2']],
    ]);

    StepsDispatcher::query()->forceCreate(['group' => $group, 'can_dispatch' => true]);

    // First dispatch - both should complete (no high priority)
    StepDispatcher::dispatch($group);

    $step1->refresh();
    $step2->refresh();

    expect($step1->state)->toBeInstanceOf(Completed::class)
        ->and($step2->state)->toBeInstanceOf(Completed::class);

    // Now create a high-priority step and a default priority step
    $step3 = Step::create([
        'block_uuid' => (string) Str::uuid(),
        'type' => 'default',
        'group' => $group,
        'state' => Pending::class,
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
        'priority' => 'high',
        'arguments' => ['custom_result' => ['executed' => 'step3-high']],
    ]);

    $step4 = Step::create([
        'block_uuid' => (string) Str::uuid(),
        'type' => 'default',
        'group' => $group,
        'state' => Pending::class,
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
        'priority' => 'default',
        'arguments' => ['custom_result' => ['executed' => 'step4-default']],
    ]);

    // Second dispatch - only high-priority should execute
    StepDispatcher::dispatch($group);

    $step3->refresh();
    $step4->refresh();

    // High-priority step should be completed
    expect($step3->state)->toBeInstanceOf(Completed::class);

    // Default priority step should still be pending
    expect($step4->state)->toBeInstanceOf(Pending::class);

    // Third dispatch - now default priority should execute
    StepDispatcher::dispatch($group);

    $step4->refresh();

    expect($step4->state)->toBeInstanceOf(Completed::class);
});

// ========================================================================
// EDGE CASES
// ========================================================================

it('always assigns a group via observer even when created with NULL group', function (): void {
    // Create a dispatcher group first so getDispatchGroup() can return it
    StepsDispatcher::query()->forceCreate(['group' => 'auto-test-group', 'can_dispatch' => true]);

    // Attempt to create steps with NULL group - observer should auto-assign a group
    $step1 = Step::create([
        'block_uuid' => (string) Str::uuid(),
        'type' => 'default',
        'state' => Pending::class,
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
        'group' => null, // Will be overridden by observer
        'priority' => 'default',
    ]);

    $step2 = Step::create([
        'block_uuid' => (string) Str::uuid(),
        'type' => 'default',
        'state' => Pending::class,
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
        'group' => null, // Will be overridden by observer
        'priority' => 'high',
    ]);

    // Steps should have a non-null group assigned by the observer
    // getDispatchGroup() returns a random group from the steps_dispatcher table
    expect($step1->group)->not->toBeNull();
    expect($step2->group)->not->toBeNull();

    // Dispatcher already created at start, just dispatch
    StepDispatcher::dispatch($step2->group);

    $step1->refresh();
    $step2->refresh();

    // High-priority step should complete (dispatched first due to priority)
    expect($step2->state)->toBeInstanceOf(Completed::class);
});

it('does not escalate priority on non-retry state transitions', function (): void {
    $group = 'test-skip-no-escalate';

    $step = Step::create([
        'block_uuid' => (string) Str::uuid(),
        'type' => 'default',
        'group' => $group,
        'state' => Pending::class,
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
        'retries' => 8, // Above 50% threshold
        'priority' => 'default',
        'arguments' => [
            'skip' => true, // Will skip instead of retry
        ],
    ]);

    StepsDispatcher::query()->forceCreate(['group' => $group, 'can_dispatch' => true]);

    StepDispatcher::dispatch($group);

    $step->refresh();

    // Should be skipped, not escalated to high priority
    expect($step->state)->toBeInstanceOf(Skipped::class)
        ->and($step->priority)->toBe('default');
});

it('handles zero retries threshold correctly', function (): void {
    $block = (string) Str::uuid();

    // Create step with 0 retries
    $step = Step::create([
        'block_uuid' => $block,
        'type' => 'default',
        'state' => Pending::class,
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
        'retries' => 0, // 0% of threshold
        'priority' => 'default',
        'arguments' => [
            'should_start_or_retry' => false, // Force retry
        ],
    ]);

    StepsDispatcher::query()->forceCreate(['group' => $block, 'can_dispatch' => true]);

    StepDispatcher::dispatch($block);

    $step->refresh();

    // Should remain default (0 < 5)
    expect($step->priority)->toBe('default');
});

it('handles max retries threshold correctly', function (): void {
    $group = 'test-max-retries';

    // Create step at max retries (10)
    $step = Step::create([
        'block_uuid' => (string) Str::uuid(),
        'type' => 'default',
        'group' => $group,
        'state' => Pending::class,
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
        'retries' => 10, // 100% of threshold
        'priority' => 'default',
        'arguments' => [
            'should_start_or_retry' => false, // Force retry
        ],
    ]);

    StepsDispatcher::query()->forceCreate(['group' => $group, 'can_dispatch' => true]);

    StepDispatcher::dispatch($group);

    $step->refresh();

    // Should be escalated to high (10 >= 5)
    expect($step->priority)->toBe('high');
});
