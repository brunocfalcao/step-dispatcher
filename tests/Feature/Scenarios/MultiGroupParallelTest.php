<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;
use StepDispatcher\Tests\Support\StepTester;

// Schematic: Group A: 1->2 | Group B: 1->2 (independent chains)
// Two completely independent chains in different groups should not interfere
it('executes independent chains in different groups without interference', function (): void {
    $blockA = (string) Str::uuid();
    $blockB = (string) Str::uuid();

    $stepsA = StepTester::createSteps([
        ['block_uuid' => $blockA, 'index' => 1],
        ['block_uuid' => $blockA, 'index' => 2],
    ], ScenarioTestJob::class);

    // Manually set group to alpha
    foreach ($stepsA as $step) {
        $step->update(['group' => 'alpha']);
    }

    $stepsB = StepTester::createSteps([
        ['block_uuid' => $blockB, 'index' => 1],
        ['block_uuid' => $blockB, 'index' => 2],
    ], ScenarioTestJob::class);

    // Manually set group to beta
    foreach ($stepsB as $step) {
        $step->update(['group' => 'beta']);
    }

    [$a1, $a2] = $stepsA;
    [$b1, $b2] = $stepsB;

    // Dispatch alpha group only in first tick
    StepDispatcher\Support\StepDispatcher::dispatch('alpha');

    $a1->refresh();
    $a2->refresh();
    $b1->refresh();
    $b2->refresh();

    // Alpha group progressed, beta group untouched
    expect($a1->state->value())->toBe('completed');
    expect($a2->state->value())->toBe('pending');
    expect($b1->state->value())->toBe('pending');
    expect($b2->state->value())->toBe('pending');

    // Dispatch beta group only in second tick
    StepDispatcher\Support\StepDispatcher::dispatch('beta');

    $a1->refresh();
    $a2->refresh();
    $b1->refresh();
    $b2->refresh();

    // Beta group progressed, alpha stayed same
    expect($a1->state->value())->toBe('completed');
    expect($a2->state->value())->toBe('pending'); // Still pending (alpha not dispatched again)
    expect($b1->state->value())->toBe('completed');
    expect($b2->state->value())->toBe('pending');

    // Dispatch alpha again to complete chain A
    StepDispatcher\Support\StepDispatcher::dispatch('alpha');

    $a1->refresh();
    $a2->refresh();
    $b1->refresh();
    $b2->refresh();

    expect($a1->state->value())->toBe('completed');
    expect($a2->state->value())->toBe('completed'); // Now completed
    expect($b1->state->value())->toBe('completed');
    expect($b2->state->value())->toBe('pending'); // Still pending

    // Dispatch beta again to complete chain B
    StepDispatcher\Support\StepDispatcher::dispatch('beta');

    $a1->refresh();
    $a2->refresh();
    $b1->refresh();
    $b2->refresh();

    expect($a1->state->value())->toBe('completed');
    expect($a2->state->value())->toBe('completed');
    expect($b1->state->value())->toBe('completed');
    expect($b2->state->value())->toBe('completed'); // Now completed
});

// Schematic: Group A: P1->C1 | Group B: P1->C1 (nested in different groups)
// Parent-child blocks in different groups should not interfere
it('executes parent-child blocks in different groups independently', function (): void {
    // Group Alpha
    $parentBlockA = (string) Str::uuid();
    $childBlockA = (string) Str::uuid();

    $parentA = StepTester::createSteps([
        ['block_uuid' => $parentBlockA, 'index' => 1, 'child_block_uuid' => $childBlockA],
    ], ScenarioTestJob::class)[0];
    $parentA->update(['group' => 'alpha']);

    $childA = StepTester::createSteps([
        ['block_uuid' => $childBlockA, 'index' => 1],
    ], ScenarioTestJob::class)[0];
    $childA->update(['group' => 'alpha']);

    // Group Beta
    $parentBlockB = (string) Str::uuid();
    $childBlockB = (string) Str::uuid();

    $parentB = StepTester::createSteps([
        ['block_uuid' => $parentBlockB, 'index' => 1, 'child_block_uuid' => $childBlockB],
    ], ScenarioTestJob::class)[0];
    $parentB->update(['group' => 'beta']);

    $childB = StepTester::createSteps([
        ['block_uuid' => $childBlockB, 'index' => 1],
    ], ScenarioTestJob::class)[0];
    $childB->update(['group' => 'beta']);

    // Dispatch alpha - parent should become running
    StepDispatcher\Support\StepDispatcher::dispatch('alpha');

    $parentA->refresh();
    $childA->refresh();
    $parentB->refresh();
    $childB->refresh();

    expect($parentA->state->value())->toBe('running');
    expect($childA->state->value())->toBe('pending');
    expect($parentB->state->value())->toBe('pending');
    expect($childB->state->value())->toBe('pending');

    // Dispatch alpha again - child should complete, parent should complete
    StepDispatcher\Support\StepDispatcher::dispatch('alpha');

    $parentA->refresh();
    $childA->refresh();
    $parentB->refresh();
    $childB->refresh();

    expect($parentA->state->value())->toBe('running');
    expect($childA->state->value())->toBe('completed');
    expect($parentB->state->value())->toBe('pending');
    expect($childB->state->value())->toBe('pending');

    // Dispatch alpha once more - parent should complete
    StepDispatcher\Support\StepDispatcher::dispatch('alpha');

    $parentA->refresh();
    $childA->refresh();
    $parentB->refresh();
    $childB->refresh();

    expect($parentA->state->value())->toBe('completed');
    expect($childA->state->value())->toBe('completed');
    expect($parentB->state->value())->toBe('pending');
    expect($childB->state->value())->toBe('pending');

    // Now dispatch beta - should go through same cycle
    StepDispatcher\Support\StepDispatcher::dispatch('beta');
    StepDispatcher\Support\StepDispatcher::dispatch('beta');
    StepDispatcher\Support\StepDispatcher::dispatch('beta');

    $parentA->refresh();
    $childA->refresh();
    $parentB->refresh();
    $childB->refresh();

    expect($parentA->state->value())->toBe('completed');
    expect($childA->state->value())->toBe('completed');
    expect($parentB->state->value())->toBe('completed');
    expect($childB->state->value())->toBe('completed');
});

// Schematic: Group A: 1 (fails) -> 2 (cancelled) | Group B: 1->2 (unaffected)
// Failure in one group should not affect another group
it('isolates failures between groups', function (): void {
    $blockA = (string) Str::uuid();
    $blockB = (string) Str::uuid();

    $stepsA = StepTester::createSteps([
        ['block_uuid' => $blockA, 'index' => 1, 'arguments' => ['fail' => true]],
        ['block_uuid' => $blockA, 'index' => 2],
    ], ScenarioTestJob::class);

    foreach ($stepsA as $step) {
        $step->update(['group' => 'alpha']);
    }

    $stepsB = StepTester::createSteps([
        ['block_uuid' => $blockB, 'index' => 1],
        ['block_uuid' => $blockB, 'index' => 2],
    ], ScenarioTestJob::class);

    foreach ($stepsB as $step) {
        $step->update(['group' => 'beta']);
    }

    [$a1, $a2] = $stepsA;
    [$b1, $b2] = $stepsB;

    // Dispatch alpha - should fail and cancel downstream
    StepDispatcher\Support\StepDispatcher::dispatch('alpha');
    StepDispatcher\Support\StepDispatcher::dispatch('alpha'); // Cascade cancellation

    $a1->refresh();
    $a2->refresh();
    $b1->refresh();
    $b2->refresh();

    expect($a1->state->value())->toBe('failed');
    expect($a2->state->value())->toBe('cancelled');
    expect($b1->state->value())->toBe('pending'); // Unaffected
    expect($b2->state->value())->toBe('pending'); // Unaffected

    // Dispatch beta - should complete normally
    StepDispatcher\Support\StepDispatcher::dispatch('beta');
    StepDispatcher\Support\StepDispatcher::dispatch('beta');

    $a1->refresh();
    $a2->refresh();
    $b1->refresh();
    $b2->refresh();

    expect($a1->state->value())->toBe('failed');
    expect($a2->state->value())->toBe('cancelled');
    expect($b1->state->value())->toBe('completed');
    expect($b2->state->value())->toBe('completed');
});

// Schematic: Same block UUID in different groups (should not happen, but test robustness)
// If somehow same block_uuid exists in multiple groups, groups should still be isolated
it('handles same block_uuid in different groups', function (): void {
    $block = (string) Str::uuid(); // Same block UUID!

    $stepsA = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    foreach ($stepsA as $step) {
        $step->update(['group' => 'alpha']);
    }

    $stepsB = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    foreach ($stepsB as $step) {
        $step->update(['group' => 'beta']);
    }

    [$a1, $a2] = $stepsA;
    [$b1, $b2] = $stepsB;

    // Dispatch alpha only
    StepDispatcher\Support\StepDispatcher::dispatch('alpha');

    $a1->refresh();
    $a2->refresh();
    $b1->refresh();
    $b2->refresh();

    // Only alpha group should progress
    expect($a1->state->value())->toBe('completed');
    expect($a2->state->value())->toBe('pending');
    expect($b1->state->value())->toBe('pending'); // Different group, not affected
    expect($b2->state->value())->toBe('pending');

    // Dispatch beta only
    StepDispatcher\Support\StepDispatcher::dispatch('beta');

    $a1->refresh();
    $a2->refresh();
    $b1->refresh();
    $b2->refresh();

    expect($a1->state->value())->toBe('completed');
    expect($a2->state->value())->toBe('pending'); // Still pending (alpha not dispatched)
    expect($b1->state->value())->toBe('completed');
    expect($b2->state->value())->toBe('pending');
});

// Schematic: 3 groups (alpha, beta, gamma) all with parallel work
// Multiple groups can be dispatched in any order without interference
it('handles three groups with interleaved dispatches', function (): void {
    $blockA = (string) Str::uuid();
    $blockB = (string) Str::uuid();
    $blockC = (string) Str::uuid();

    $stepA = StepTester::createSteps([['block_uuid' => $blockA, 'index' => 1]], ScenarioTestJob::class)[0];
    $stepA->update(['group' => 'alpha']);

    $stepB = StepTester::createSteps([['block_uuid' => $blockB, 'index' => 1]], ScenarioTestJob::class)[0];
    $stepB->update(['group' => 'beta']);

    $stepC = StepTester::createSteps([['block_uuid' => $blockC, 'index' => 1]], ScenarioTestJob::class)[0];
    $stepC->update(['group' => 'gamma']);

    // Interleave dispatches: beta, alpha, gamma
    StepDispatcher\Support\StepDispatcher::dispatch('beta');

    $stepA->refresh();
    $stepB->refresh();
    $stepC->refresh();

    expect($stepA->state->value())->toBe('pending');
    expect($stepB->state->value())->toBe('completed');
    expect($stepC->state->value())->toBe('pending');

    StepDispatcher\Support\StepDispatcher::dispatch('alpha');

    $stepA->refresh();
    $stepB->refresh();
    $stepC->refresh();

    expect($stepA->state->value())->toBe('completed');
    expect($stepB->state->value())->toBe('completed');
    expect($stepC->state->value())->toBe('pending');

    StepDispatcher\Support\StepDispatcher::dispatch('gamma');

    $stepA->refresh();
    $stepB->refresh();
    $stepC->refresh();

    expect($stepA->state->value())->toBe('completed');
    expect($stepB->state->value())->toBe('completed');
    expect($stepC->state->value())->toBe('completed');
});

// Schematic: Group alpha with chainable workflow
// Test a complex chainable workflow with multiple sequential and parallel steps
it('handles complex chainable workflow in single group', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1], // Sequential start
        ['block_uuid' => $block, 'index' => 2], // Parallel block start
        ['block_uuid' => $block, 'index' => 2],
        ['block_uuid' => $block, 'index' => 2],
        ['block_uuid' => $block, 'index' => 3], // Sequential after parallel
        ['block_uuid' => $block, 'index' => 4], // Final step
    ], ScenarioTestJob::class);

    foreach ($steps as $step) {
        $step->update(['group' => 'alpha']);
    }

    [$s1, $s2a, $s2b, $s2c, $s3, $s4] = $steps;

    $statusMatrix = [
        1 => [
            $s1->id => 'completed',
            $s2a->id => 'pending',
            $s2b->id => 'pending',
            $s2c->id => 'pending',
            $s3->id => 'pending',
            $s4->id => 'pending',
        ],
        2 => [
            $s1->id => 'completed',
            $s2a->id => 'completed',
            $s2b->id => 'completed',
            $s2c->id => 'completed',
            $s3->id => 'pending',
            $s4->id => 'pending',
        ],
        3 => [
            $s1->id => 'completed',
            $s2a->id => 'completed',
            $s2b->id => 'completed',
            $s2c->id => 'completed',
            $s3->id => 'completed',
            $s4->id => 'pending',
        ],
        4 => [
            $s1->id => 'completed',
            $s2a->id => 'completed',
            $s2b->id => 'completed',
            $s2c->id => 'completed',
            $s3->id => 'completed',
            $s4->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('complex_chainable_workflow')
        ->test();
});
