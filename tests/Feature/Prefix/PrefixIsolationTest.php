<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\Steps;

/**
 * The whole point of the prefix feature: writes under one prefix
 * land in that prefix's table set, never in the default tables.
 * If this test breaks, the feature is dead.
 *
 * Each prefix is installed via `steps:install --prefix=X` (the
 * canonical install path) so the test exercises the same
 * end-to-end shape a host app would.
 */
beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());

    $this->artisan('steps:install', ['--prefix' => 'trading'])->assertSuccessful();
    $this->artisan('steps:install', ['--prefix' => 'calc'])->assertSuccessful();
});

it('Step::create under ambient prefix lands in the prefixed table only', function () {
    Steps::usingPrefix('trading', function (): void {
        Step::create([
            'class' => 'App\\Jobs\\TestJob',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'iso-test-grp',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);
    });

    expect(DB::table('trading_steps')->count())->toBe(1,
        'A Step::create issued inside `Steps::usingPrefix("trading")` '
        .'must write to `trading_steps` exclusively. Anything else '
        .'breaks the isolation contract that justifies the entire feature.'
    );

    expect(DB::table('steps')->count())->toBe(0,
        'The default unprefixed `steps` table must remain empty. '
        .'A leak here means the ambient prefix did not flow through '
        .'to Eloquent — the worst kind of silent failure.'
    );

    expect(DB::table('calc_steps')->count())->toBe(0);
});

it('two prefixes coexist with no cross-table leakage', function () {
    Steps::usingPrefix('trading', function (): void {
        Step::create([
            'class' => 'App\\Jobs\\TradingJob',
            'type' => 'default',
            'queue' => 'positions',
            'group' => 'trading-iso',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);
        Step::create([
            'class' => 'App\\Jobs\\TradingJob2',
            'type' => 'default',
            'queue' => 'positions',
            'group' => 'trading-iso',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);
    });

    Steps::usingPrefix('calc', function (): void {
        Step::create([
            'class' => 'App\\Jobs\\CalcJob',
            'type' => 'default',
            'queue' => 'indicators',
            'group' => 'calc-iso',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);
    });

    expect(DB::table('trading_steps')->count())->toBe(2);
    expect(DB::table('calc_steps')->count())->toBe(1);
    expect(DB::table('steps')->count())->toBe(0);
});

it('explicit Step::prefix() override writes to the named prefix even when ambient is different', function () {
    Steps::usingPrefix('trading', function (): void {
        // Inside a trading-ambient handler. Most writes go to
        // trading_steps (ambient). One row explicitly fans out
        // into calc_steps via the per-call override.
        Step::create([
            'class' => 'App\\Jobs\\TradingJob',
            'type' => 'default',
            'queue' => 'positions',
            'group' => 'mix-test',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);

        Step::prefix('calc')->create([
            'class' => 'App\\Jobs\\CalcChildJob',
            'type' => 'default',
            'queue' => 'indicators',
            'group' => 'mix-test',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);

        Step::create([
            'class' => 'App\\Jobs\\TradingJob2',
            'type' => 'default',
            'queue' => 'positions',
            'group' => 'mix-test',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);
    });

    expect(DB::table('trading_steps')->count())->toBe(2,
        'Two ambient writes landed in trading_steps, the explicit '
        .'override for calc did not consume them.'
    );
    expect(DB::table('calc_steps')->count())->toBe(1,
        'The single explicit Step::prefix("calc") write landed in '
        .'calc_steps without disturbing the surrounding trading-ambient '
        .'block. This is the cross-tier escape hatch contract.'
    );
});

it('tableName() helpers resolve to the prefixed names under ambient prefix', function () {
    Steps::usingPrefix('trading', function (): void {
        expect(Step::tableName())->toBe('trading_steps');
        expect(StepDispatcher\Models\StepsDispatcher::tableName())->toBe('trading_steps_dispatcher');
        expect(StepDispatcher\Models\StepsDispatcherTicks::tableName())->toBe('trading_steps_dispatcher_ticks');
        expect(StepDispatcher\Models\StepsArchive::tableName())->toBe('trading_steps_archive');
    });

    expect(Step::tableName())->toBe('steps',
        'After the closure exits the ambient is gone — tableName() '
        .'resolves to the unprefixed default. This is the path the '
        .'observer chain takes when the worker handler has not pushed '
        .'a prefix yet (job booting on a fresh process).'
    );
});

it('install command is idempotent on a fully installed prefix (no-op, no duplicate seeds)', function () {
    // beforeEach already installed `trading`. A second install with
    // the same prefix must succeed as a no-op: every target table
    // already exists, nothing is recreated, and crucially the
    // dispatcher group seed does NOT fire again (otherwise the
    // alpha..kappa rows would duplicate every time the operator
    // re-runs the command for a sanity check).
    $seedCountBefore = DB::table('trading_steps_dispatcher')->count();

    $exitCode = $this->artisan('steps:install', ['--prefix' => 'trading'])->run();

    expect($exitCode)->toBe(0,
        'Re-running install on a complete set must succeed as a no-op. '
        .'The previous "atomic refuse" semantics blocked operators '
        .'from re-running the command after a partial manual drop — '
        .'forcing them to drop everything and reinstall just to heal '
        .'one missing table.'
    );

    expect(DB::table('trading_steps_dispatcher')->count())->toBe($seedCountBefore,
        'The dispatcher seed must NOT fire on a skip path. A re-seed '
        .'would duplicate alpha..kappa rows on every re-run, breaking '
        .'the unique-on-group constraint and silently corrupting the '
        .'round-robin selection.'
    );
});

it('install command heals a partial drop by creating only the missing tables', function () {
    // Simulate an operator who manually dropped one of the prefixed
    // tables (e.g. `trading_steps_archive` for a clean reset of
    // archived rows). Re-running install must NOT touch the three
    // surviving tables and must create only the missing one.
    Schema::drop('trading_steps_archive');

    $stepsRowCountBefore = DB::table('trading_steps_dispatcher')->count();

    $exitCode = $this->artisan('steps:install', ['--prefix' => 'trading'])->run();

    expect($exitCode)->toBe(0);
    expect(Schema::hasTable('trading_steps_archive'))->toBeTrue(
        'The missing archive table must be re-created on the heal pass.'
    );
    expect(DB::table('trading_steps_dispatcher')->count())->toBe($stepsRowCountBefore,
        'Surviving dispatcher table must NOT be re-seeded. The seed '
        .'fires only on the path that genuinely creates the dispatcher '
        .'table; a heal-pass that only creates archive must leave '
        .'dispatcher untouched.'
    );
});

it('install command rejects an empty prefix', function () {
    $exitCode = $this->artisan('steps:install', ['--prefix' => ''])->run();

    expect($exitCode)->toBe(1,
        'An empty prefix is the default tables, owned by the '
        .'package migrations. The install command must not '
        .'compete with `php artisan migrate` on that scope.'
    );
});
