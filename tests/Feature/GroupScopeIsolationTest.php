<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Group Scope Isolation
|--------------------------------------------------------------------------
|
| `group` is the dispatcher's isolation lane: a tick for group X must only
| read and mutate group X steps, and a tick for the null lane (group=null)
| must only touch steps whose group IS NULL — the same semantics the
| dispatch-phase base query has always had (whereNull else-branch).
|
| Historically the cleanup phases (cascades, parent transitions) omitted
| the whereNull branch, so a null-lane tick swept parents from EVERY
| group — racing the per-group ticks that own them (the per-group CAS
| lock only serializes ticks of the same group).
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

/**
 * Build a Running parent (with child block) whose child has Failed —
 * the exact shape transitionParentsToFailed acts on.
 */
function makeFailedChildTree(?string $group): array
{
    $blockUuid = 'scope-parent-'.uniqid();
    $childBlockUuid = 'scope-children-'.uniqid();

    $parent = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => $blockUuid,
        'child_block_uuid' => $childBlockUuid,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => $group ?? 'placeholder',
        'state' => Pending::class,
    ]);

    $child = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => $group ?? 'placeholder',
        'state' => Pending::class,
    ]);

    // Raw writes bypass the observer (its saving() hook backfills any
    // empty group), pinning the exact lane — the same way null-group
    // rows arise in production: raw DB inserts from consumer tooling.
    DB::table(Step::tableName())->where('id', $parent->id)
        ->update(['state' => Running::class, 'group' => $group]);
    DB::table(Step::tableName())->where('id', $child->id)
        ->update(['state' => Failed::class, 'group' => $group]);

    return [$parent->fresh(), $child->fresh()];
}

it('a null-lane tick does not transition parents belonging to a named group', function (): void {
    [$namedParent] = makeFailedChildTree('named-group');

    StepDispatcher::dispatch(null);

    $namedParent->refresh();
    expect($namedParent->state)->toBeInstanceOf(Running::class);
});

it('a null-lane tick still transitions parents in the null lane', function (): void {
    [$nullParent] = makeFailedChildTree(null);

    StepDispatcher::dispatch(null);

    $nullParent->refresh();
    expect($nullParent->state)->toBeInstanceOf(Failed::class);
});

it('a named-group tick does not transition parents of another group', function (): void {
    [$otherParent] = makeFailedChildTree('other-group');

    StepDispatcher::dispatch('test-group');

    $otherParent->refresh();
    expect($otherParent->state)->toBeInstanceOf(Running::class);
});
