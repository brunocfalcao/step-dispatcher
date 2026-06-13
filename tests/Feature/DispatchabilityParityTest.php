<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Transitions\PendingToDispatched;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Dispatchability Parity Contract
|--------------------------------------------------------------------------
|
| Two implementations decide whether a Pending step may dispatch:
|
|   1. StepDispatcher::computeDispatchableSteps — the batch/in-memory
|      path every tick actually runs.
|   2. PendingToDispatched::canTransition — the per-step rule set, in
|      both its cache-backed and DB-query variants.
|
| They MUST agree for every step shape. This matrix pins the contract so
| a rule change applied to one implementation fails loudly when the
| other is missed — drift between them surfaces only under specific
| production load patterns otherwise.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

/**
 * Evaluate one Pending step through all three deciders and assert they
 * produce the same verdict.
 */
function assertDispatchParity(Step $step, bool $expected, string $scenario): void
{
    $pendingSteps = collect([$step]);
    $stepsCache = StepDispatcher::buildStepsCache($pendingSteps, 'test-group');

    $batchVerdict = StepDispatcher::computeDispatchableSteps($pendingSteps, $stepsCache)
        ->contains('id', $step->id);

    $cacheVerdict = (new PendingToDispatched($step, $stepsCache))->canTransition();
    $dbVerdict = (new PendingToDispatched($step))->canTransition();

    expect($batchVerdict)->toBe($expected, "batch path disagrees: {$scenario}")
        ->and($cacheVerdict)->toBe($expected, "cache canTransition disagrees: {$scenario}")
        ->and($dbVerdict)->toBe($expected, "db canTransition disagrees: {$scenario}");
}

function parityStep(array $attributes, ?string $forcedState = null): Step
{
    $step = Step::create(array_merge([
        'class' => PrefixCarryingTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'state' => Pending::class,
    ], $attributes));

    if ($forcedState !== null) {
        DB::table(Step::tableName())->where('id', $step->id)->update(['state' => $forcedState]);
    }

    return $step->fresh();
}

it('agrees across all dispatchability shapes', function (): void {
    // 1. Orphan at index 1 → dispatchable.
    $s = parityStep(['block_uuid' => (string) Str::uuid(), 'index' => 1]);
    assertDispatchParity($s, true, 'orphan index 1');

    // 2. Orphan at index 2 with previous index Completed → dispatchable.
    $block = (string) Str::uuid();
    parityStep(['block_uuid' => $block, 'index' => 1], Completed::class);
    $s = parityStep(['block_uuid' => $block, 'index' => 2]);
    assertDispatchParity($s, true, 'orphan index 2, prev completed');

    // 3. Orphan at index 2 with previous index still Pending → blocked.
    $block = (string) Str::uuid();
    parityStep(['block_uuid' => $block, 'index' => 1]);
    $s = parityStep(['block_uuid' => $block, 'index' => 2]);
    assertDispatchParity($s, false, 'orphan index 2, prev pending');

    // 4. Child at index 1 of a Running parent → dispatchable.
    $childBlock = (string) Str::uuid();
    parityStep(['block_uuid' => (string) Str::uuid(), 'child_block_uuid' => $childBlock, 'index' => 1], Running::class);
    $s = parityStep(['block_uuid' => $childBlock, 'index' => 1]);
    assertDispatchParity($s, true, 'child of running parent, index 1');

    // 5. Child at index 1 of a Pending parent → blocked.
    $childBlock = (string) Str::uuid();
    parityStep(['block_uuid' => (string) Str::uuid(), 'child_block_uuid' => $childBlock, 'index' => 1]);
    $s = parityStep(['block_uuid' => $childBlock, 'index' => 1]);
    assertDispatchParity($s, false, 'child of pending parent');

    // 6. Null-index child of a Running parent → dispatchable. The
    //    observer normalizes index null→1 on create, so force null raw
    //    (raw inserts are how null-index rows exist in the wild).
    $childBlock = (string) Str::uuid();
    parityStep(['block_uuid' => (string) Str::uuid(), 'child_block_uuid' => $childBlock, 'index' => 1], Running::class);
    $s = parityStep(['block_uuid' => $childBlock, 'index' => 1]);
    DB::table(Step::tableName())->where('id', $s->id)->update(['index' => null]);
    $s = $s->fresh();
    assertDispatchParity($s, true, 'null-index child of running parent');

    // 7. Parent step (spawns children) at index 1 → dispatchable.
    $s = parityStep(['block_uuid' => (string) Str::uuid(), 'child_block_uuid' => (string) Str::uuid(), 'index' => 1]);
    assertDispatchParity($s, true, 'parent step index 1');

    // 8. Parallel sibling: two steps at index 2; previous index has one
    //    Completed and one still Running → blocked for both paths.
    $block = (string) Str::uuid();
    parityStep(['block_uuid' => $block, 'index' => 1], Completed::class);
    parityStep(['block_uuid' => $block, 'index' => 1], Running::class);
    $s = parityStep(['block_uuid' => $block, 'index' => 2]);
    assertDispatchParity($s, false, 'parallel previous index partially running');
});
