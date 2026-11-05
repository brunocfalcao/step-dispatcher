<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\RuntimeContext;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Support\Steps;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Queued job restoration
|--------------------------------------------------------------------------
|
| A worker rebuilds the job from its queue payload. The rebuilt job must load
| its step from the right table, keep the dispatch it belongs to, and leave no
| prefix behind for the next job in the same worker process.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    app(RuntimeContext::class)->reset();
    $this->artisan('steps:install', ['--prefix' => 'trading'])->assertSuccessful();
});

function restorableStep(): Step
{
    return Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'restoration-test',
        'state' => Pending::class,
    ]);
}

/**
 * Build the job exactly as the dispatcher does and capture what it pushes.
 */
function payloadFromDispatcher(Step $step): PrefixCarryingTestJob
{
    Queue::fake();

    DB::table(Step::tableName())->where('id', $step->id)->update(['state' => Dispatched::class, 'tick_id' => 42]);

    (new StepDispatcher)->dispatchSingleStep($step->fresh());

    $pushed = null;
    Queue::assertPushed(PrefixCarryingTestJob::class, static function (PrefixCarryingTestJob $job) use (&$pushed): bool {
        $pushed = $job;

        return true;
    });

    return unserialize(serialize($pushed));
}

it('rebuilds a queued job from its prefixed table with its dispatch intact', function (): void {
    $restored = Steps::usingPrefix('trading', static function (): PrefixCarryingTestJob {
        return payloadFromDispatcher(restorableStep());
    });

    expect(app(RuntimeContext::class)->depth())->toBe(0)
        ->and($restored->stepPrefix)->toBe('trading_')
        ->and($restored->stepAttemptSnapshotAvailable)->toBeTrue()
        ->and($restored->stepAttemptRetries)->toBe(0)
        ->and($restored->stepAttemptTickId)->toBe(42)
        ->and($restored->stepAttemptStartedAt)->toBeNull();

    $restored->handle();

    $row = Steps::usingPrefix('trading', static fn (): Step => Step::findOrFail($restored->step->id));

    expect($row->state)->toBeInstanceOf(Completed::class)
        ->and($row->response)->toBe(['observed_prefix' => 'trading_'])
        ->and(app(RuntimeContext::class)->depth())->toBe(0);
});

it('does not start a rebuilt job when the step was dispatched again meanwhile', function (): void {
    $step = restorableStep();
    $restored = payloadFromDispatcher($step);

    DB::table(Step::tableName())->where('id', $step->id)->update(['tick_id' => 43]);

    $restored->handle();

    expect($step->fresh()->state)->toBeInstanceOf(Dispatched::class);
});

it('leaves no prefix behind when the step of a queued job no longer exists', function (): void {
    $payload = Steps::usingPrefix('trading', static function (): string {
        $step = restorableStep();
        $job = new PrefixCarryingTestJob;
        $job->step = $step;
        $job->stepPrefix = 'trading_';
        $payload = serialize($job);
        DB::table(Step::tableName())->where('id', $step->id)->delete();

        return $payload;
    });

    expect(fn () => unserialize($payload))->toThrow(ModelNotFoundException::class)
        ->and(app(RuntimeContext::class)->depth())->toBe(0);
});
