<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Stopped Parent Guards
|--------------------------------------------------------------------------
|
| transitionParentsToStopped must apply the same wait-gates as
| transitionParentsToFailed:
|
|   1. Parallel siblings — consumers run several children at the SAME
|      index (Kraite: 3 analyst steps at index 1, balance fan-outs, BTC
|      correlation/elasticity pairs). When one sibling stops, the parent
|      must wait for the others to reach a terminal state; stopping the
|      parent while a sibling is still Running orphans that sibling's
|      outcome.
|
|   2. Resolve-exception steps — a non-terminal resolve-exception step in
|      the child block gets to run before the parent is concluded.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function makeStoppedTree(array $children): Step
{
    $blockUuid = 'stop-guard-parent-'.uniqid();
    $childBlockUuid = 'stop-guard-children-'.uniqid();

    $parent = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => $blockUuid,
        'child_block_uuid' => $childBlockUuid,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'state' => Pending::class,
    ]);
    DB::table(Step::tableName())->where('id', $parent->id)->update(['state' => Running::class]);

    foreach ($children as $child) {
        $step = Step::create([
            'class' => PrefixCarryingTestJob::class,
            'block_uuid' => $childBlockUuid,
            'index' => $child['index'],
            'type' => $child['type'] ?? 'default',
            'queue' => 'default',
            'group' => 'test-group',
            'state' => Pending::class,
        ]);
        DB::table(Step::tableName())->where('id', $step->id)->update(['state' => $child['state']]);
    }

    return $parent->fresh();
}

it('waits for a running parallel sibling before stopping the parent', function (): void {
    $parent = makeStoppedTree([
        ['index' => 1, 'state' => Stopped::class],
        ['index' => 1, 'state' => Running::class],
    ]);

    StepDispatcher::dispatch('test-group');

    $parent->refresh();
    expect($parent->state)->toBeInstanceOf(Running::class);
});

it('stops the parent once all parallel siblings are terminal', function (): void {
    $parent = makeStoppedTree([
        ['index' => 1, 'state' => Stopped::class],
        ['index' => 1, 'state' => Completed::class],
    ]);

    StepDispatcher::dispatch('test-group');

    $parent->refresh();
    expect($parent->state)->toBeInstanceOf(Stopped::class);
});

it('waits for a non-terminal resolve-exception step before stopping the parent', function (): void {
    $parent = makeStoppedTree([
        ['index' => 1, 'state' => Stopped::class],
        ['index' => null, 'state' => NotRunnable::class, 'type' => 'resolve-exception'],
    ]);

    StepDispatcher::dispatch('test-group');

    $parent->refresh();
    expect($parent->state)->toBeInstanceOf(Running::class);
});
