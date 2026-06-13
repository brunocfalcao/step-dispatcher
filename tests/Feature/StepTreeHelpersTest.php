<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;

/*
|--------------------------------------------------------------------------
| Step Tree Helpers
|--------------------------------------------------------------------------
|
| The dispatcher's dispatchability and conclusion logic is built on these
| relationship predicates. They are pure functions of the block graph, so
| they get pinned directly here rather than only through full ticks.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function makeStep(array $attributes, ?string $forcedState = null): Step
{
    $step = Step::create(array_merge([
        'class' => 'App\\EchoJob',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'tree-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ], $attributes));

    if ($forcedState !== null) {
        DB::table(Step::tableName())->where('id', $step->id)->update(['state' => $forcedState]);
    }

    return $step->fresh();
}

it('distinguishes parent, child, and orphan steps', function (): void {
    $childBlock = (string) Str::uuid();
    $parent = makeStep(['block_uuid' => (string) Str::uuid(), 'child_block_uuid' => $childBlock]);
    $child = makeStep(['block_uuid' => $childBlock]);
    $orphan = makeStep(['block_uuid' => (string) Str::uuid()]);

    expect($parent->isParent())->toBeTrue()
        ->and($parent->isChild())->toBeFalse()
        ->and($parent->isOrphan())->toBeFalse()
        ->and($child->isParent())->toBeFalse()
        ->and($child->isChild())->toBeTrue()
        ->and($orphan->isParent())->toBeFalse()
        ->and($orphan->isChild())->toBeFalse()
        ->and($orphan->isOrphan())->toBeTrue();
});

it('resolves parentStep and parentIsRunning', function (): void {
    $childBlock = (string) Str::uuid();
    $parent = makeStep(['block_uuid' => (string) Str::uuid(), 'child_block_uuid' => $childBlock], Running::class);
    $child = makeStep(['block_uuid' => $childBlock]);

    expect($child->parentStep()->is($parent))->toBeTrue()
        ->and($child->parentIsRunning())->toBeTrue();
});

it('reports hasChildren only when the child block is populated', function (): void {
    $populatedBlock = (string) Str::uuid();
    $parentWithKids = makeStep(['block_uuid' => (string) Str::uuid(), 'child_block_uuid' => $populatedBlock]);
    makeStep(['block_uuid' => $populatedBlock]);

    $parentNoKids = makeStep(['block_uuid' => (string) Str::uuid(), 'child_block_uuid' => (string) Str::uuid()]);

    expect($parentWithKids->hasChildren())->toBeTrue()
        ->and($parentNoKids->hasChildren())->toBeFalse();
});

it('flags a dormant resolve-exception step', function (): void {
    // type=resolve-exception → observer stamps NotRunnable on create.
    $dormant = makeStep(['type' => 'resolve-exception', 'index' => null]);
    $regular = makeStep([]);

    expect($dormant->isDormantResolveException())->toBeTrue()
        ->and($regular->isDormantResolveException())->toBeFalse();
});

it('concludes a previous index only when it is terminal', function (): void {
    $block = (string) Str::uuid();
    makeStep(['block_uuid' => $block, 'index' => 1], Completed::class);
    $secondDone = makeStep(['block_uuid' => $block, 'index' => 2]);

    $blockPending = (string) Str::uuid();
    makeStep(['block_uuid' => $blockPending, 'index' => 1]); // stays Pending
    $secondBlocked = makeStep(['block_uuid' => $blockPending, 'index' => 2]);

    $first = makeStep(['block_uuid' => (string) Str::uuid(), 'index' => 1]);

    expect($first->previousIndexIsConcluded())->toBeTrue() // index 1: nothing before it
        ->and($secondDone->previousIndexIsConcluded())->toBeTrue()
        ->and($secondBlocked->previousIndexIsConcluded())->toBeFalse();
});

it('reports childStepsAreConcluded across the child block', function (): void {
    $childBlock = (string) Str::uuid();
    $parent = makeStep(['block_uuid' => (string) Str::uuid(), 'child_block_uuid' => $childBlock]);
    $child = makeStep(['block_uuid' => $childBlock], Completed::class);

    expect($parent->childStepsAreConcluded())->toBeTrue();

    DB::table(Step::tableName())->where('id', $child->id)->update(['state' => Pending::class]);

    expect($parent->childStepsAreConcluded())->toBeFalse();
});

it('returns the previous-index steps via getPrevious', function (): void {
    $block = (string) Str::uuid();
    $first = makeStep(['block_uuid' => $block, 'index' => 1]);
    $second = makeStep(['block_uuid' => $block, 'index' => 2]);

    $previous = $second->getPrevious();

    expect($previous)->toHaveCount(1)
        ->and($previous->first()->id)->toBe($first->id);
});
