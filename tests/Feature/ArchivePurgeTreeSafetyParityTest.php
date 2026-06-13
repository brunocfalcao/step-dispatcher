<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Archive / Purge Tree Parity & Cycle Safety
|--------------------------------------------------------------------------
|
| 1. State-list parity: archive treats NotRunnable (parked resolve-
|    exception steps) as settled; purge historically did not. A tree that
|    finished with a never-promoted resolve-exception step could be
|    archived but never purged from the live table — drifting forever.
|    Both commands share one settled-states list now.
|
| 2. Cycle safety: collectTree walks child_block_uuid links. A data cycle
|    (no FK prevents one) must terminate the walk, not hang the command.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function makeAgedStep(array $attributes): Step
{
    $step = Step::create(array_merge([
        'class' => PrefixCarryingTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
    ], $attributes));

    DB::table(Step::tableName())->where('id', $step->id)->update(array_merge(
        ['created_at' => now()->subDays(60), 'updated_at' => now()->subDays(60), 'completed_at' => now()->subDays(60)],
        array_intersect_key($attributes, array_flip(['state'])),
    ));

    return $step->fresh();
}

it('purges a settled tree that contains a NotRunnable resolve-exception step', function (): void {
    $blockUuid = (string) Str::uuid();

    makeAgedStep(['block_uuid' => $blockUuid, 'index' => 1, 'state' => Completed::class]);
    makeAgedStep(['block_uuid' => $blockUuid, 'index' => null, 'type' => 'resolve-exception', 'state' => NotRunnable::class]);

    Artisan::call('steps:purge', ['--days' => 30]);

    expect(DB::table(Step::tableName())->where('block_uuid', $blockUuid)->count())->toBe(0);
});

/**
 * Build a tree whose root is clean but whose descendants cycle:
 * root R → A → B → A. No FK prevents this shape; collectTree must
 * terminate instead of bouncing A→B→A forever.
 *
 * @return array{string, string, string} [$rootBlock, $blockA, $blockB]
 */
function makeCyclicTree(): array
{
    $rootBlock = (string) Str::uuid();
    $blockA = (string) Str::uuid();
    $blockB = (string) Str::uuid();

    makeAgedStep(['block_uuid' => $rootBlock, 'child_block_uuid' => $blockA, 'state' => Completed::class]);
    makeAgedStep(['block_uuid' => $blockA, 'child_block_uuid' => $blockB, 'state' => Completed::class]);
    makeAgedStep(['block_uuid' => $blockB, 'child_block_uuid' => $blockA, 'state' => Completed::class]);

    return [$rootBlock, $blockA, $blockB];
}

it('archives a tree containing a child-block cycle without hanging', function (): void {
    [$rootBlock, $blockA, $blockB] = makeCyclicTree();

    Artisan::call('steps:archive', ['--duration' => 30]);

    expect(DB::table(Step::tableName())->whereIn('block_uuid', [$rootBlock, $blockA, $blockB])->count())->toBe(0);
});

it('purges a tree containing a child-block cycle without hanging', function (): void {
    [$rootBlock, $blockA, $blockB] = makeCyclicTree();

    Artisan::call('steps:purge', ['--days' => 30]);

    expect(DB::table(Step::tableName())->whereIn('block_uuid', [$rootBlock, $blockA, $blockB])->count())->toBe(0);
});
