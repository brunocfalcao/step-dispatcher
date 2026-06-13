<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Transitions\PendingToDispatched;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Dispatch Claim Race Safety
|--------------------------------------------------------------------------
|
| The dispatcher selects Pending steps, evaluates dispatchability on the
| in-memory snapshot, then applies PendingToDispatched. External writers
| (operator cancels, consumer recovery commands like Kraite's
| RecoverPositionsCommand) can move the same row to Cancelled between
| selection and apply. The apply must therefore be an atomic claim —
| UPDATE ... WHERE state = Pending — never a blind save() that would
| resurrect a cancelled step into Dispatched and execute it.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function makeClaimStep(): Step
{
    return Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => 'claim-race-'.uniqid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'state' => Pending::class,
    ]);
}

it('does not resurrect a concurrently-cancelled step into Dispatched', function (): void {
    $step = makeClaimStep();

    // Dispatcher selected the step (in-memory snapshot says Pending)...
    $transition = new PendingToDispatched($step);
    expect($transition->canTransition())->toBeTrue();

    // ...then an external writer cancels it before apply.
    DB::table(Step::tableName())->where('id', $step->id)->update(['state' => Cancelled::class]);

    $result = $transition->apply();

    $step->refresh();
    expect($result)->toBeNull()
        ->and($step->state)->toBeInstanceOf(Cancelled::class);
});

it('claims and dispatches a step that is still Pending', function (): void {
    $step = makeClaimStep();

    $transition = new PendingToDispatched($step);
    $result = $transition->apply();

    $step->refresh();
    expect($result)->not->toBeNull()
        ->and($step->state)->toBeInstanceOf(Dispatched::class);
});

it('does not clobber a concurrent requeue to Pending when the queue push fails', function (): void {
    // A dispatch failure (resolver throw, Redis down) must only fail the
    // step if it is still in the Dispatched state this tick put it in.
    // If recover-stale concurrently requeued it to Pending, transitioning
    // to Failed would erase that recovery (Pending → Failed is a
    // registered transition, so without the state re-check it succeeds).
    $step = makeClaimStep();

    StepDispatcher::setQueueResolver(static function (Step $resolving): string {
        // Simulate recover-stale winning the race mid-dispatch, then the
        // dispatch failing.
        DB::table(Step::tableName())->where('id', $resolving->id)->update(['state' => Pending::class]);

        throw new RuntimeException('queue backend unavailable');
    });

    StepDispatcher::dispatch('test-group');

    StepDispatcher::setQueueResolver(null);

    $step->refresh();
    expect($step->state)->toBeInstanceOf(Pending::class);
});

it('fails the step when the dispatch push fails and no one else touched it', function (): void {
    $step = makeClaimStep();

    StepDispatcher::setQueueResolver(static function (Step $resolving): string {
        throw new RuntimeException('queue backend unavailable');
    });

    StepDispatcher::dispatch('test-group');

    StepDispatcher::setQueueResolver(null);

    $step->refresh();
    expect($step->state)->toBeInstanceOf(\StepDispatcher\States\Failed::class)
        ->and($step->error_message)->toContain('queue backend unavailable')
        ->and($step->error_stack_trace)->not->toBeNull();
});
