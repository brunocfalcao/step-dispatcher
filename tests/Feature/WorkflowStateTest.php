<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Enums\WorkflowState;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Workflow global state
|--------------------------------------------------------------------------
|
| StepDispatcher::workflowState($uuid) is THE canonical answer to "how is
| this workflow doing" — consumers must never hand-roll their own
| aggregation queries. Contract:
|
|   unknown    no live steps carry the workflow_id (archived is archived)
|   pending    every step still Pending — nothing has started
|   running    anything in flight, including a promoted resolve-exception
|              recovering a failure
|   failed     any Failed/Stopped (or their cascade-Cancelled fallout) once
|              nothing is left running — even when a resolve-exception
|              recovery completed: recovery restores a STABLE state, not a
|              successful one
|   completed  every necessary step concluded; dormant (never promoted)
|              NotRunnable resolve-exception steps are ignored
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function makeWorkflowStep(
    string $workflowId,
    string $blockUuid,
    int $index,
    string $state,
    string $type = 'default',
): Step {
    $step = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'workflow_id' => $workflowId,
        'block_uuid' => $blockUuid,
        'index' => $index,
        'type' => $type,
        'queue' => 'default',
        'state' => Pending::class,
    ]);

    // Force the target state raw: the observer parks resolve-exception
    // steps as NotRunnable on creation, and this helper needs to model
    // PROMOTED resolvers (Pending) too.
    Step::where('id', $step->id)->update(['state' => $state]);

    return $step->refresh();
}

test('an unknown uuid returns unknown', function (): void {
    expect(StepDispatcher::workflowState(Str::uuid()->toString()))
        ->toBe(WorkflowState::Unknown);
});

test('all steps pending means the workflow is pending', function (): void {
    $workflow = Str::uuid()->toString();
    $block = Str::uuid()->toString();

    makeWorkflowStep($workflow, $block, 1, Pending::class);
    makeWorkflowStep($workflow, $block, 2, Pending::class);

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Pending);
});

test('dormant resolve-exception steps do not make a fresh workflow look started', function (): void {
    $workflow = Str::uuid()->toString();
    $block = Str::uuid()->toString();

    makeWorkflowStep($workflow, $block, 1, Pending::class);
    makeWorkflowStep($workflow, $block, 2, NotRunnable::class, type: 'resolve-exception');

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Pending);
});

test('a running step means the workflow is running', function (): void {
    $workflow = Str::uuid()->toString();
    $block = Str::uuid()->toString();

    makeWorkflowStep($workflow, $block, 1, Running::class);
    makeWorkflowStep($workflow, $block, 2, Pending::class);

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Running);
});

test('a dispatched step means the workflow is running', function (): void {
    $workflow = Str::uuid()->toString();
    $block = Str::uuid()->toString();

    makeWorkflowStep($workflow, $block, 1, Dispatched::class);

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Running);
});

test('a started workflow with remaining pending steps is running, not pending', function (): void {
    $workflow = Str::uuid()->toString();
    $block = Str::uuid()->toString();

    makeWorkflowStep($workflow, $block, 1, Completed::class);
    makeWorkflowStep($workflow, $block, 2, Pending::class);

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Running);
});

test('a failure whose promoted resolve-exception is still pending reads running', function (): void {
    $workflow = Str::uuid()->toString();
    $block = Str::uuid()->toString();

    makeWorkflowStep($workflow, $block, 1, Failed::class);
    makeWorkflowStep($workflow, $block, 2, Pending::class, type: 'resolve-exception');

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Running);
});

test('a failure whose resolve-exception recovery completed still reads failed', function (): void {
    $workflow = Str::uuid()->toString();
    $block = Str::uuid()->toString();

    makeWorkflowStep($workflow, $block, 1, Failed::class);
    makeWorkflowStep($workflow, $block, 2, Completed::class, type: 'resolve-exception');

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Failed);
});

test('a failed step with cascade-cancelled successors reads failed once settled', function (): void {
    $workflow = Str::uuid()->toString();
    $block = Str::uuid()->toString();

    makeWorkflowStep($workflow, $block, 1, Completed::class);
    makeWorkflowStep($workflow, $block, 2, Failed::class);
    makeWorkflowStep($workflow, $block, 3, Cancelled::class);

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Failed);
});

test('a stopped step reads failed once settled', function (): void {
    $workflow = Str::uuid()->toString();
    $block = Str::uuid()->toString();

    makeWorkflowStep($workflow, $block, 1, Stopped::class);

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Failed);
});

test('all steps concluded reads completed', function (): void {
    $workflow = Str::uuid()->toString();
    $block = Str::uuid()->toString();

    makeWorkflowStep($workflow, $block, 1, Completed::class);
    makeWorkflowStep($workflow, $block, 2, Skipped::class);

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Completed);
});

test('dormant resolve-exception steps never block completion', function (): void {
    $workflow = Str::uuid()->toString();
    $block = Str::uuid()->toString();

    makeWorkflowStep($workflow, $block, 1, Completed::class);
    makeWorkflowStep($workflow, $block, 2, NotRunnable::class, type: 'resolve-exception');

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Completed);
});

test('workflow state spans parent and child blocks sharing the workflow id', function (): void {
    $workflow = Str::uuid()->toString();
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    $parent = makeWorkflowStep($workflow, $parentBlock, 1, Running::class);
    Step::where('id', $parent->id)->update(['child_block_uuid' => $childBlock]);

    makeWorkflowStep($workflow, $childBlock, 1, Completed::class);
    makeWorkflowStep($workflow, $childBlock, 2, Pending::class);

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Running);
});

test('archived workflows read unknown — archived is archived', function (): void {
    $workflow = Str::uuid()->toString();
    $block = Str::uuid()->toString();

    makeWorkflowStep($workflow, $block, 1, Completed::class);

    // Archival moves rows out of the live table; the contract does not
    // chase them into the archive.
    Step::where('workflow_id', $workflow)->delete();

    expect(StepDispatcher::workflowState($workflow))->toBe(WorkflowState::Unknown);
});
