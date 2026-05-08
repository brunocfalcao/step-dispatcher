<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Support\Steps;

/**
 * End-to-end smoke for a full dispatcher tick under a prefix. Every
 * upstream piece (RuntimeContext, model getTable resolution, cache
 * key scoping, raw CTE interpolation, dispatcher start/end DB writes)
 * must compose into one cohesive tick that:
 *   • reads Pending steps from `trading_steps`
 *   • opens + closes a tick row in `trading_steps_dispatcher_ticks`
 *   • creates / updates the per-group lock row in `trading_steps_dispatcher`
 *   • promotes Pending → Dispatched on the prefixed rows ONLY
 *   • leaves every default (unprefixed) table at exactly 0 rows
 *
 * If any single one of those bullet points fails we get a silent
 * cross-prefix leak — the worst-shape failure mode for this feature.
 */
beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    Queue::fake();

    $this->artisan('steps:install', ['--prefix' => 'trading'])->assertSuccessful();
});

it('a full dispatcher tick under a prefix stays inside the prefixed table set', function () {
    Steps::usingPrefix('trading', function (): void {
        // Seed three Pending steps under group `alpha` directly into
        // trading_steps. The observer will not auto-pick a group
        // (we set it explicitly), so SQLite-incompatible round-robin
        // SQL stays out of the path.
        collect(range(1, 3))->each(static function (): void {
            Step::create([
                'class' => 'App\\Jobs\\TestJob',
                'type' => 'default',
                'queue' => 'default',
                'group' => 'alpha',
                'index' => null,
                'block_uuid' => (string) Str::uuid(),
                'state' => Pending::class,
            ]);
        });
    });

    // Pre-tick sanity — trading_steps holds the work, default
    // `steps` is empty. The dispatcher tables both already have the
    // package's seeded group rows from `steps:install`, so we don't
    // assert zero on those — only that `trading_steps_dispatcher_ticks`
    // is empty (no tick has been opened yet).
    expect(DB::table('trading_steps')->count())->toBe(3);
    expect(DB::table('steps')->count())->toBe(0);
    expect(DB::table('trading_steps_dispatcher_ticks')->count())->toBe(0);
    expect(DB::table('steps_dispatcher_ticks')->count())->toBe(0);

    Steps::usingPrefix('trading', function (): void {
        StepDispatcher::dispatch('alpha');
    });

    // Tick lifecycle: alpha lock row got its current_tick_id set by
    // startDispatch and cleared back to null by endDispatch — so the
    // observable side-effect on the dispatcher table is the
    // last_tick_completed timestamp landing on the trading row.
    expect(
        DB::table('trading_steps_dispatcher')
            ->where('group', 'alpha')
            ->whereNotNull('last_tick_completed')
            ->count()
    )->toBe(1,
        'endDispatch must stamp last_tick_completed on the trading_'
        .'steps_dispatcher alpha row. If the dispatcher escaped the '
        .'prefix mid-tick, the timestamp lands on the default row '
        .'instead.'
    );

    expect(DB::table('trading_steps_dispatcher_ticks')->count())->toBeGreaterThanOrEqual(1,
        'A tick must be recorded in the prefixed ticks table — the '
        .'dispatcher-side audit trail. Empty here means the tick '
        .'creation path resolved to the wrong table or was skipped.'
    );

    // Steps must have moved past Pending under the prefix. With
    // Queue::fake() in place the queue worker never runs the job, so
    // the dispatcher's promotion (Pending → Dispatched) is the
    // observable end-state.
    Steps::usingPrefix('trading', function (): void {
        $stillPending = Step::where('state', Pending::class)
            ->where('group', 'alpha')
            ->count();

        expect($stillPending)->toBeLessThan(3,
            'At least one trading_steps row must have been promoted '
            .'past Pending. The dispatcher tick read its work queue '
            .'from the prefixed table — if it still reads from the '
            .'default `steps` table, every trading step stays Pending '
            .'forever (the silent wedge).'
        );
    });

    // Cross-prefix isolation — the default tables must remain
    // pristine. A non-zero count anywhere here is a bug, not a smell.
    expect(DB::table('steps')->count())->toBe(0,
        'The default `steps` table is empty in this test. Anything '
        .'else means the dispatcher leaked a write across prefixes.'
    );

    // Default `steps_dispatcher` row count is whatever the package
    // migrations seeded (the same 10 group rows as the prefixed set);
    // we don't assert that count, but we DO assert that nothing
    // touched the default rows during the trading tick — none of
    // them got a current_tick_id assigned, none of them stamped
    // last_tick_completed.
    expect(
        DB::table('steps_dispatcher')->whereNotNull('current_tick_id')->count()
    )->toBe(0,
        'No default-prefix dispatcher row should hold a current_tick_id '
        .'after a trading-only tick. A non-null value here = startDispatch '
        .'wrote to the default table path despite the runtime prefix.'
    );

    expect(
        DB::table('steps_dispatcher')->whereNotNull('last_tick_completed')->count()
    )->toBe(0,
        'No default row should have last_tick_completed stamped. The '
        .'trading tick must not bleed into the default audit trail.'
    );

    expect(DB::table('steps_dispatcher_ticks')->count())->toBe(0,
        'No tick row should land in the default ticks table. A row '
        .'here = the tick lifecycle escaped the prefix mid-run.'
    );
});

it('a default-prefix dispatcher tick alongside a trading tick keeps both table sets isolated', function () {
    // Seed work in BOTH table sets.
    Step::create([
        'class' => 'App\\Jobs\\DefaultJob',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'alpha',
        'index' => null,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ]);

    Steps::usingPrefix('trading', function (): void {
        Step::create([
            'class' => 'App\\Jobs\\TradingJob',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'alpha',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);
    });

    expect(DB::table('steps')->count())->toBe(1);
    expect(DB::table('trading_steps')->count())->toBe(1);

    // Run the default tick first, then the trading tick. Each tick
    // must claim its own lock row, write its own tick row, and not
    // see / mutate the other side's state.
    StepDispatcher::dispatch('alpha');

    Steps::usingPrefix('trading', function (): void {
        StepDispatcher::dispatch('alpha');
    });

    // Both alpha rows must have been touched by their respective
    // dispatchers — last_tick_completed is the cleanest stamp.
    expect(
        DB::table('steps_dispatcher')
            ->where('group', 'alpha')
            ->whereNotNull('last_tick_completed')
            ->count()
    )->toBe(1,
        'Default alpha row got its last_tick_completed stamped by the '
        .'default-prefix tick.'
    );

    expect(
        DB::table('trading_steps_dispatcher')
            ->where('group', 'alpha')
            ->whereNotNull('last_tick_completed')
            ->count()
    )->toBe(1,
        'Trading alpha row got its last_tick_completed stamped by the '
        .'trading-prefix tick — independently of the default row.'
    );

    expect(DB::table('steps_dispatcher_ticks')->count())->toBeGreaterThanOrEqual(1,
        'Default tick was recorded in the default ticks table.'
    );
    expect(DB::table('trading_steps_dispatcher_ticks')->count())->toBeGreaterThanOrEqual(1,
        'Trading tick was recorded in the trading ticks table — '
        .'separate from the default tick row.'
    );
});
