<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsArchive;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Exception triage — analysed flag + persisted verdict
|--------------------------------------------------------------------------
|
| A failed step is terminal; triage happens ON TOP of the state machine:
| `exception_analysed` marks a failure as handled by the operator (so a
| consumer's failures view can hide it), `exception_verdict` persists a
| diagnosis (e.g. AI-generated) for later re-reading. Neither is a state.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function makeTriageStep(array $attributes = []): Step
{
    return Step::create(array_merge([
        'block_uuid' => (string) Str::uuid(),
        'class' => PrefixCarryingTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'triage-group',
        'index' => 1,
        'state' => Failed::class,
        'error_message' => 'triage: exploded',
    ], $attributes));
}

it('ships the triage columns on the base steps and archive tables', function (): void {
    expect(Schema::hasColumn(Step::tableName(), 'exception_analysed'))->toBeTrue()
        ->and(Schema::hasColumn(Step::tableName(), 'exception_verdict'))->toBeTrue()
        ->and(Schema::hasColumn(StepsArchive::tableName(), 'exception_analysed'))->toBeTrue()
        ->and(Schema::hasColumn(StepsArchive::tableName(), 'exception_verdict'))->toBeTrue();
});

it('creates the triage columns on a freshly installed prefixed table set', function (): void {
    Artisan::call('steps:install', ['--prefix' => 'triagetest']);

    expect(Schema::hasColumn('triagetest_steps', 'exception_analysed'))->toBeTrue()
        ->and(Schema::hasColumn('triagetest_steps', 'exception_verdict'))->toBeTrue()
        ->and(Schema::hasColumn('triagetest_steps_archive', 'exception_analysed'))->toBeTrue()
        ->and(Schema::hasColumn('triagetest_steps_archive', 'exception_verdict'))->toBeTrue();

    Schema::dropIfExists('triagetest_steps');
    Schema::dropIfExists('triagetest_steps_dispatcher');
    Schema::dropIfExists('triagetest_steps_dispatcher_ticks');
    Schema::dropIfExists('triagetest_steps_archive');
});

it('marks only the targeted step analysed, defaulting new steps to unanalysed', function (): void {
    // Read back from the DB — the column default only exists there, a
    // freshly created in-memory model never had the attribute set.
    $failed = makeTriageStep(['error_message' => 'triage: target'])->fresh();
    $sibling = makeTriageStep(['error_message' => 'triage: sibling'])->fresh();

    expect($failed->exception_analysed)->toBeFalse()
        ->and($sibling->exception_analysed)->toBeFalse();

    $failed->exceptionWasAnalysed();

    expect($failed->fresh()->exception_analysed)->toBeTrue()
        ->and($failed->fresh()->state)->toBeInstanceOf(Failed::class)
        ->and($sibling->fresh()->exception_analysed)->toBeFalse();
});

it('persists a verdict without marking the exception analysed', function (): void {
    $failed = makeTriageStep();

    $failed->storeExceptionVerdict('Rate limit burst from the hourly refresh; retry after widening the throttler window.');

    $fresh = $failed->fresh();
    expect($fresh->exception_verdict)->toBe('Rate limit burst from the hourly refresh; retry after widening the throttler window.')
        ->and($fresh->exception_analysed)->toBeFalse();
});

it('carries the triage columns into the archive verbatim', function (): void {
    $blockUuid = (string) Str::uuid();
    $step = makeTriageStep([
        'block_uuid' => $blockUuid,
        'state' => Completed::class,
        'exception_verdict' => 'diagnosed: transient DNS wedge',
    ]);
    $step->exceptionWasAnalysed();

    // Age the tree past the archive horizon.
    DB::table(Step::tableName())->where('id', $step->id)->update([
        'created_at' => now()->subDays(60),
        'updated_at' => now()->subDays(60),
        'completed_at' => now()->subDays(60),
    ]);

    Artisan::call('steps:archive', ['--duration' => 30]);

    $archived = DB::table(StepsArchive::tableName())->where('block_uuid', $blockUuid)->first();
    expect($archived)->not->toBeNull()
        ->and((bool) $archived->exception_analysed)->toBeTrue()
        ->and($archived->exception_verdict)->toBe('diagnosed: transient DNS wedge')
        ->and(DB::table(Step::tableName())->where('block_uuid', $blockUuid)->count())->toBe(0);
});
