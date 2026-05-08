<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Skipped;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Support\Steps;

/**
 * The recursive CTE in `collectAllNestedChildBlocks()` walks the
 * step tree via raw SQL. Bypassing Eloquent means the model's
 * getTable() resolution does not apply automatically — the
 * package must interpolate Step::tableName() into the SQL string.
 * If that interpolation breaks, the prefixed dispatcher silently
 * scans the wrong table and either misses descendants entirely
 * or pulls in unrelated rows from the default table.
 */
beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    $this->artisan('steps:install', ['--prefix' => 'trading'])->assertSuccessful();
});

it('recursive child-block collection uses the prefixed table under prefix', function () {
    Steps::usingPrefix('trading', function (): void {
        // Build a 3-level skipped-parent tree in trading_steps:
        //   parent (Skipped, child_block_uuid=A)
        //     ├── childA1 (in block A, child_block_uuid=B)
        //     └── childA2 (in block A, terminal)
        //         └── grandchildB1 (in block B, Pending — non-terminal)
        $blockRoot = (string) Str::uuid();
        $blockA = (string) Str::uuid();
        $blockB = (string) Str::uuid();

        Step::create([
            'class' => 'App\\Jobs\\ParentJob',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'cte-test',
            'index' => 1,
            'block_uuid' => $blockRoot,
            'child_block_uuid' => $blockA,
            'state' => Skipped::class,
        ]);

        Step::create([
            'class' => 'App\\Jobs\\ChildA1',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'cte-test',
            'index' => 1,
            'block_uuid' => $blockA,
            'child_block_uuid' => $blockB,
            'state' => Pending::class,
        ]);

        Step::create([
            'class' => 'App\\Jobs\\ChildA2',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'cte-test',
            'index' => 2,
            'block_uuid' => $blockA,
            'state' => Pending::class,
        ]);

        Step::create([
            'class' => 'App\\Jobs\\GrandchildB1',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'cte-test',
            'index' => 1,
            'block_uuid' => $blockB,
            'state' => Pending::class,
        ]);

        // Sanity — confirm rows landed in the prefixed table.
        expect(DB::table('trading_steps')->count())->toBe(4);
        expect(DB::table('steps')->count())->toBe(0);

        $skippedParents = Step::where('state', Skipped::class)->get();
        $reachable = StepDispatcher::collectAllNestedChildBlocks($skippedParents, 'cte-test');

        // The CTE should walk: blockA (direct child of root) + blockB
        // (child of blockA via the chain). Two distinct UUIDs.
        sort($reachable);
        $expected = [$blockA, $blockB];
        sort($expected);

        expect($reachable)->toBe($expected,
            'Recursive CTE must traverse the full descendant chain '
            .'in `trading_steps`. If the SQL still says `FROM steps`, '
            .'this query returns an empty array (default `steps` is '
            .'empty in this test) and the dispatcher would never '
            .'cascade-skip the descendants of a Skipped parent — a '
            .'silent contract violation.'
        );
    });
});
