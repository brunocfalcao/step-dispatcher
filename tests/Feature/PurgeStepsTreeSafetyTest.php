<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;

beforeEach(function () {
    // The Step observer's `created()` hook activates the dispatcher, which
    // requires a flag path. Tests don't actually dispatch so the path can
    // point to any writable dir.
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

/**
 * Tree-safety contract for PurgeStepsCommand.
 *
 * A step tree is a rooted DAG linked via child_block_uuid. Purging by
 * `created_at < cutoff` alone is unsafe: a root step can be weeks old and
 * in a terminal state while its descendants are still live (long-running
 * workflow, stuck leaf, new branch spawned recently). Deleting the parent
 * orphans those descendants, breaks the workflow, and loses audit trail.
 *
 * These tests assert that `steps:purge` only removes rows when the ENTIRE
 * tree reachable from a root block is in a terminal state.
 */
function seedStepRow(array $attrs): Step
{
    // Explicit non-empty group bypasses the observer's round-robin group
    // assignment (getNextGroup), which uses MySQL-specific NOW(6) and can't
    // run on the SQLite testing connection.
    return Step::create(array_merge([
        'class' => 'App\\Jobs\\TestJob',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
    ], $attrs));
}

it('keeps an old root step when one of its descendants is still Running', function () {
    $rootBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();
    $cutoff = Carbon::now()->subDays(30)->subMinute();

    // Root (old, terminal) — Completed 30+ days ago
    $root = seedStepRow([
        'block_uuid' => $rootBlock,
        'child_block_uuid' => $childBlock,
    ]);
    Step::withoutEvents(function () use ($root, $cutoff) {
        Step::where('id', $root->id)->update([
            'state' => Completed::class,
            'created_at' => $cutoff,
            'updated_at' => $cutoff,
            'completed_at' => $cutoff,
        ]);
    });

    // Child (also old) still Running — the workflow never concluded
    $child = seedStepRow(['block_uuid' => $childBlock]);
    Step::withoutEvents(function () use ($child, $cutoff) {
        Step::where('id', $child->id)->update([
            'state' => Running::class,
            'created_at' => $cutoff,
            'updated_at' => $cutoff,
            'started_at' => $cutoff,
        ]);
    });

    Artisan::call('steps:purge', ['--days' => 30]);

    // Both rows must survive — deleting the root orphans the live child.
    expect(Step::find($root->id))->not->toBeNull('root must be kept while live child exists');
    expect(Step::find($child->id))->not->toBeNull('live child must survive purge');
});

it('keeps an old root when a grandchild is still Pending', function () {
    $rootBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();
    $grandchildBlock = (string) Str::uuid();
    $cutoff = Carbon::now()->subDays(30)->subMinute();

    $root = seedStepRow([
        'block_uuid' => $rootBlock,
        'child_block_uuid' => $childBlock,
    ]);
    Step::withoutEvents(function () use ($root, $cutoff) {
        Step::where('id', $root->id)->update([
            'state' => Completed::class,
            'created_at' => $cutoff,
            'updated_at' => $cutoff,
        ]);
    });

    $child = seedStepRow([
        'block_uuid' => $childBlock,
        'child_block_uuid' => $grandchildBlock,
    ]);
    Step::withoutEvents(function () use ($child, $cutoff) {
        Step::where('id', $child->id)->update([
            'state' => Completed::class,
            'created_at' => $cutoff,
            'updated_at' => $cutoff,
        ]);
    });

    $grandchild = seedStepRow(['block_uuid' => $grandchildBlock]);
    Step::withoutEvents(function () use ($grandchild, $cutoff) {
        Step::where('id', $grandchild->id)->update([
            'state' => Pending::class,
            'created_at' => $cutoff,
            'updated_at' => $cutoff,
        ]);
    });

    Artisan::call('steps:purge', ['--days' => 30]);

    expect(Step::find($root->id))->not->toBeNull();
    expect(Step::find($child->id))->not->toBeNull();
    expect(Step::find($grandchild->id))->not->toBeNull();
});

it('deletes a root when every step in its tree is terminal', function () {
    $rootBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();
    $cutoff = Carbon::now()->subDays(30)->subMinute();

    $root = seedStepRow([
        'block_uuid' => $rootBlock,
        'child_block_uuid' => $childBlock,
    ]);
    Step::withoutEvents(function () use ($root, $cutoff) {
        Step::where('id', $root->id)->update([
            'state' => Completed::class,
            'created_at' => $cutoff,
            'updated_at' => $cutoff,
        ]);
    });

    $child = seedStepRow(['block_uuid' => $childBlock]);
    Step::withoutEvents(function () use ($child, $cutoff) {
        Step::where('id', $child->id)->update([
            'state' => Completed::class,
            'created_at' => $cutoff,
            'updated_at' => $cutoff,
        ]);
    });

    Artisan::call('steps:purge', ['--days' => 30]);

    expect(Step::find($root->id))->toBeNull('fully-terminal tree should be deleted');
    expect(Step::find($child->id))->toBeNull('fully-terminal tree should be deleted');
});

it('does not touch recent steps regardless of tree state', function () {
    $rootBlock = (string) Str::uuid();

    $recent = seedStepRow(['block_uuid' => $rootBlock]);
    Step::withoutEvents(function () use ($recent) {
        Step::where('id', $recent->id)->update(['state' => Completed::class]);
    });

    Artisan::call('steps:purge', ['--days' => 30]);

    expect(Step::find($recent->id))->not->toBeNull('rows younger than cutoff must stay');
});
