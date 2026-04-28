<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;

beforeEach(function () {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

/**
 * Insert a row into steps_archive with explicit created_at. The archive
 * table is schema-identical to steps for the columns we care about, but
 * has no observers / model — direct DB insert keeps the test crisp.
 */
function seedArchiveRow(Carbon $createdAt, array $overrides = []): int
{
    $now = Carbon::now()->toDateTimeString();

    return DB::table('steps_archive')->insertGetId(array_merge([
        'block_uuid' => (string) Str::uuid(),
        'class' => 'App\\Jobs\\Archived',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'state' => StepDispatcher\States\Completed::class,
        'index' => 1,
        'created_at' => $createdAt->toDateTimeString(),
        'updated_at' => $now,
    ], $overrides));
}

/**
 * Insert a live steps row with explicit created_at, bypassing the
 * Step observer's group round-robin (which depends on MySQL NOW(6)
 * and breaks on SQLite).
 */
function seedLiveStepRow(Carbon $createdAt): Step
{
    return Step::create([
        'class' => 'App\\Jobs\\Live',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'state' => StepDispatcher\States\Completed::class,
        'created_at' => $createdAt,
    ]);
}

/**
 * `steps:purge --only-archive` is the destination of the archive ageing
 * pipeline: ArchiveStepsCommand moves cooled trees from steps →
 * steps_archive on a daily cadence, then this purge eventually drops
 * archive rows once they're older than the retention window. Crucially,
 * the live `steps` table must remain untouched — purging there has its
 * own tree-safety contract, covered by PurgeStepsTreeSafetyTest.
 */
it('deletes only archive rows older than --days when --only-archive is set', function () {
    $oldId = seedArchiveRow(Carbon::now()->subDays(40));
    $borderlineId = seedArchiveRow(Carbon::now()->subDays(31));
    $recentId = seedArchiveRow(Carbon::now()->subDays(5));

    Artisan::call('steps:purge', ['--only-archive' => true, '--days' => 30]);

    expect(DB::table('steps_archive')->where('id', $oldId)->exists())->toBeFalse();
    expect(DB::table('steps_archive')->where('id', $borderlineId)->exists())->toBeFalse();
    expect(DB::table('steps_archive')->where('id', $recentId)->exists())->toBeTrue();
});

it('leaves the live steps table completely untouched when --only-archive is set', function () {
    // Older-than-cutoff live step. Without --only-archive, the original
    // command would attempt the tree-aware purge on this row. With
    // --only-archive the live table is off-limits — guarantees the
    // archive purge can never accidentally drop rows that the dispatcher
    // is still observing.
    $liveStep = seedLiveStepRow(Carbon::now()->subDays(40));
    seedArchiveRow(Carbon::now()->subDays(40));

    Artisan::call('steps:purge', ['--only-archive' => true, '--days' => 30]);

    expect(Step::query()->where('id', $liveStep->id)->exists())->toBeTrue();
    expect(DB::table('steps_archive')->count())->toBe(0);
});

it('does not touch ticks when --only-archive is set', function () {
    DB::table('steps_dispatcher_ticks')->insert([
        'group' => 'test-group',
        'progress' => 0,
        'started_at' => Carbon::now()->subDays(40)->toDateTimeString(),
        'completed_at' => Carbon::now()->subDays(40)->toDateTimeString(),
        'duration' => 0,
        'created_at' => Carbon::now()->subDays(40)->toDateTimeString(),
        'updated_at' => Carbon::now()->subDays(40)->toDateTimeString(),
    ]);

    Artisan::call('steps:purge', ['--only-archive' => true, '--days' => 30]);

    // The ticks date-purge belongs to the default purge mode. Under
    // --only-archive we keep the contract narrow: archive table only.
    expect(DB::table('steps_dispatcher_ticks')->count())->toBe(1);
});

it('rejects --only-archive when --days is below 1', function () {
    $exitCode = Artisan::call('steps:purge', ['--only-archive' => true, '--days' => 0]);

    expect($exitCode)->not->toBe(0);
});

it('default purge mode (no --only-archive) still walks the live steps table', function () {
    // Regression guard for the existing tree-aware purge — confirms the
    // new flag is purely additive and doesn't accidentally short-circuit
    // the original code path.
    $cutoff = Carbon::now()->subDays(31);

    $rootBlock = (string) Str::uuid();
    Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'state' => StepDispatcher\States\Completed::class,
        'block_uuid' => $rootBlock,
        'created_at' => $cutoff,
        'updated_at' => $cutoff,
        'completed_at' => $cutoff,
    ]);

    Artisan::call('steps:purge', ['--days' => 30]);

    expect(Step::query()->where('block_uuid', $rootBlock)->exists())->toBeFalse();
});
