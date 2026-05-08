<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\Steps;

/**
 * Archive + Purge are the two retention commands that run on every
 * production host. Under a prefixed dispatcher they MUST stay inside
 * the prefixed table set: read source rows from `trading_steps`,
 * write destination rows to `trading_steps_archive`, never touch the
 * default `steps` / `steps_archive`. A leak in either direction is a
 * silent data corruption — the kind that only surfaces weeks later
 * when a host runs both a default and a prefixed dispatcher and rows
 * mysteriously appear in the wrong table set.
 */
beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    $this->artisan('steps:install', ['--prefix' => 'trading'])->assertSuccessful();
});

it('steps:archive --prefix=trading copies from trading_steps to trading_steps_archive only', function () {
    $cutoff = Carbon::now()->subDays(10);

    // Seed a fully-terminal single-block tree in trading_steps,
    // dated past the archive cutoff so the command picks it up.
    Steps::usingPrefix('trading', function () use ($cutoff): void {
        $blockUuid = (string) Str::uuid();

        $step = Step::create([
            'class' => 'App\\Jobs\\TerminalJob',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'archive-test',
            'index' => 1,
            'block_uuid' => $blockUuid,
            'state' => Completed::class,
        ]);

        // Backdate the row past the archive cutoff. Observers are
        // skipped so we don't trip the dispatcher activation flag a
        // second time during this update.
        Step::withoutEvents(function () use ($step, $cutoff): void {
            Step::where('id', $step->id)->update([
                'state' => Completed::class,
                'created_at' => $cutoff,
                'updated_at' => $cutoff,
                'completed_at' => $cutoff,
            ]);
        });
    });

    // Sanity — the row is in trading_steps and nowhere else before
    // the archive command runs.
    expect(DB::table('trading_steps')->count())->toBe(1);
    expect(DB::table('steps')->count())->toBe(0);
    expect(DB::table('trading_steps_archive')->count())->toBe(0);
    expect(DB::table('steps_archive')->count())->toBe(0);

    Artisan::call('steps:archive', ['--prefix' => 'trading', '--duration' => 5]);

    expect(DB::table('trading_steps_archive')->count())->toBe(1,
        'The archived step must land in trading_steps_archive — that '
        .'is the prefix-scoped destination resolved through '
        .'StepsArchive::tableName(). Anything in default '
        .'`steps_archive` means the raw INSERT/SELECT picked up the '
        .'wrong table.'
    );

    expect(DB::table('trading_steps')->count())->toBe(0,
        'Source row must be deleted from trading_steps — the archive '
        .'command moves rows, it does not duplicate them.'
    );

    expect(DB::table('steps_archive')->count())->toBe(0,
        'Default unprefixed `steps_archive` must remain empty. A non-'
        .'zero count means INSERT INTO leaked across prefixes — the '
        .'exact regression Step 4 of the audit was meant to prevent.'
    );

    expect(DB::table('steps')->count())->toBe(0,
        'And the default `steps` table was never touched on this run.'
    );
});

it('steps:purge --prefix=trading --only-archive deletes only from trading_steps_archive', function () {
    $cutoff = Carbon::now()->subDays(60);
    $now = Carbon::now()->toDateTimeString();

    // Seed an old archive row in trading_steps_archive directly
    // (the archive table has no observers, plain DB insert is fine).
    DB::table('trading_steps_archive')->insert([
        'block_uuid' => (string) Str::uuid(),
        'class' => 'App\\Jobs\\Archived',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'purge-test',
        'state' => Completed::class,
        'index' => 1,
        'created_at' => $cutoff->toDateTimeString(),
        'updated_at' => $now,
    ]);

    // Seed an old archive row in DEFAULT steps_archive — nothing
    // should touch this row when the purge runs under the trading
    // prefix.
    DB::table('steps_archive')->insert([
        'block_uuid' => (string) Str::uuid(),
        'class' => 'App\\Jobs\\Archived',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'purge-test',
        'state' => Completed::class,
        'index' => 1,
        'created_at' => $cutoff->toDateTimeString(),
        'updated_at' => $now,
    ]);

    expect(DB::table('trading_steps_archive')->count())->toBe(1);
    expect(DB::table('steps_archive')->count())->toBe(1);

    Artisan::call('steps:purge', [
        '--prefix' => 'trading',
        '--days' => 30,
        '--only-archive' => true,
    ]);

    expect(DB::table('trading_steps_archive')->count())->toBe(0,
        'Trading archive row was older than the 30d retention — must '
        .'be deleted. The purge command resolved StepsArchive::'
        .'tableName() to trading_steps_archive correctly.'
    );

    expect(DB::table('steps_archive')->count())->toBe(1,
        'Default archive row must SURVIVE — it belongs to the default '
        .'(unprefixed) dispatcher, which has its own retention pipeline. '
        .'Cross-prefix purge here would be silent data loss.'
    );
});

it('steps:purge --prefix=trading deletes only from prefixed steps + ticks tables (tree-safe)', function () {
    $cutoff = Carbon::now()->subDays(40);

    // Seed: one fully-terminal block in trading_steps, one live block
    // in trading_steps (must survive), one terminal block in default
    // steps (must survive — wrong prefix).
    Steps::usingPrefix('trading', function () use ($cutoff): void {
        $terminalBlock = (string) Str::uuid();
        $liveBlock = (string) Str::uuid();

        $terminal = Step::create([
            'class' => 'App\\Jobs\\Terminal',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'tx-purge',
            'index' => 1,
            'block_uuid' => $terminalBlock,
            'state' => Completed::class,
        ]);

        Step::withoutEvents(function () use ($terminal, $cutoff): void {
            Step::where('id', $terminal->id)->update([
                'state' => Completed::class,
                'created_at' => $cutoff,
                'updated_at' => $cutoff,
                'completed_at' => $cutoff,
            ]);
        });

        $live = Step::create([
            'class' => 'App\\Jobs\\Live',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'tx-purge',
            'index' => 1,
            'block_uuid' => $liveBlock,
            'state' => Pending::class,
        ]);

        // Even though we'd never delete a Pending row by tree rules,
        // keep its updated_at recent so the test reads cleanly.
        Step::withoutEvents(function () use ($live): void {
            Step::where('id', $live->id)->update([
                'state' => Running::class,
                'updated_at' => Carbon::now(),
            ]);
        });
    });

    // Default-table sentinel — must NOT be touched by a trading purge.
    $defaultStep = Step::create([
        'class' => 'App\\Jobs\\DefaultTerminal',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'tx-purge',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
        'state' => Completed::class,
    ]);

    Step::withoutEvents(function () use ($defaultStep, $cutoff): void {
        Step::where('id', $defaultStep->id)->update([
            'state' => Completed::class,
            'created_at' => $cutoff,
            'updated_at' => $cutoff,
            'completed_at' => $cutoff,
        ]);
    });

    expect(DB::table('trading_steps')->count())->toBe(2);
    expect(DB::table('steps')->count())->toBe(1);

    Artisan::call('steps:purge', ['--prefix' => 'trading', '--days' => 30]);

    expect(DB::table('trading_steps')->count())->toBe(1,
        'Only the fully-terminal old block in trading_steps must be '
        .'deleted. The live (Running) row must survive — that is the '
        .'tree-safety contract under any prefix.'
    );

    expect(DB::table('steps')->count())->toBe(1,
        'Default steps row must SURVIVE the trading purge entirely. '
        .'Cross-prefix delete = silent corruption.'
    );
});
