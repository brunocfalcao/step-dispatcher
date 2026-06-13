<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Hot-Path Query Budgets
|--------------------------------------------------------------------------
|
| Loose ceilings on the per-row query cost of the two hottest paths:
|
| 1. StepObserver inheritance on Step::create() — bulk inserts of 200-step
|    blocks fire the observer per row; every extra lookup multiplies.
|
| 2. recover-stale descendant walk — one query per child per level made a
|    50-parent watchdog run cost hundreds of round-trips. The walk must
|    be bounded by tree depth, not node count.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function countQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('creates a child step with at most 2 inheritance lookups plus the insert', function (): void {
    $childBlockUuid = (string) Str::uuid();

    // Parent carrying workflow_id, priority and group — the worst case
    // pre-consolidation: workflow parent + priority parent + group parent
    // lookups all fired separately.
    Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => (string) Str::uuid(),
        'child_block_uuid' => $childBlockUuid,
        'index' => 1,
        'type' => 'default',
        'queue' => 'priority',
        'priority' => 'high',
        'group' => 'test-group',
        'workflow_id' => (string) Str::uuid(),
        'state' => Pending::class,
    ]);

    $queries = countQueries(static function () use ($childBlockUuid): void {
        Step::create([
            'class' => PrefixCarryingTestJob::class,
            'block_uuid' => $childBlockUuid,
            'index' => 1,
            'type' => 'default',
            'queue' => 'default',
            'state' => Pending::class,
        ]);
    });

    // 1 parent lookup + 1 insert (+ 1 slack): the consolidated observer
    // resolves workflow_id, priority and group from a single parent fetch.
    expect($queries)->toBeLessThanOrEqual(3);
});

it('walks a deep stale-parent tree with queries bounded by depth, not node count', function (): void {
    // Stale Running parent with a 2-level descendant tree fanning out to
    // many leaves. Per-child recursion costs O(nodes); the level walk
    // costs O(depth) + 1 terminality check.
    $rootChildBlock = (string) Str::uuid();

    $root = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => (string) Str::uuid(),
        'child_block_uuid' => $rootChildBlock,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'state' => Pending::class,
    ]);

    // Level 1: 10 parents, each spawning a level-2 block of 5 leaves.
    foreach (range(1, 10) as $i) {
        $leafBlock = (string) Str::uuid();

        Step::create([
            'class' => PrefixCarryingTestJob::class,
            'block_uuid' => $rootChildBlock,
            'child_block_uuid' => $leafBlock,
            'index' => $i,
            'type' => 'default',
            'queue' => 'default',
            'group' => 'test-group',
            'state' => Pending::class,
        ]);

        foreach (range(1, 5) as $j) {
            Step::create([
                'class' => PrefixCarryingTestJob::class,
                'block_uuid' => $leafBlock,
                'index' => $j,
                'type' => 'default',
                'queue' => 'default',
                'group' => 'test-group',
                'state' => Pending::class,
            ]);
        }
    }

    // Every descendant terminal: the walk must visit the WHOLE tree
    // before concluding the root is a genuine zombie. (Any non-terminal
    // child short-circuits the walk and hides the per-node cost.)
    DB::table(Step::tableName())
        ->where('id', '!=', $root->id)
        ->update(['state' => \StepDispatcher\States\Completed::class]);

    DB::table(Step::tableName())->where('id', $root->id)->update([
        'state' => Running::class,
        'started_at' => now()->subMinutes(10),
    ]);

    $queries = countQueries(static function (): void {
        Artisan::call('steps:recover-stale');
    });

    // Stale scan + level walk (2 levels) + 1 terminality check + the
    // recovery writes. Pre-fix the walk alone cost one query per
    // level-1 parent (10+) on top of that.
    expect($queries)->toBeLessThanOrEqual(12);
});
