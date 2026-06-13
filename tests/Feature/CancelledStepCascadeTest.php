<?php

declare(strict_types=1);

use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Cancelled Step Cascade
|--------------------------------------------------------------------------
|
| Consumers cancel in-flight steps externally (e.g. Kraite's
| RecoverPositionsCommand writes state=Cancelled directly onto step rows
| referencing wiped positions). A Cancelled step is terminal but is NOT a
| "concluded" state — successors at higher indexes in the same block can
| never satisfy previousIndexIsConcluded and would sit Pending forever,
| wedging the block and tripping the group-progress watchdog.
|
| The dispatcher's cascade phase must therefore treat an externally
| Cancelled step exactly like a Failed/Stopped one: cancel all runnable
| steps at higher indexes in the same block.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function makeBlockStep(string $blockUuid, int $index, string $state, string $group = 'test-group'): Step
{
    $step = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => $index,
        'type' => 'default',
        'queue' => 'default',
        'group' => $group,
        'state' => Pending::class,
    ]);

    if ($state !== Pending::class) {
        // External write, mimicking consumer-side cancellation/conclusion
        // that bypasses the state machine (RecoverPositionsCommand pattern).
        $step->update(['state' => $state]);
        $step->refresh();
    }

    return $step;
}

it('cancels pending successors of an externally-cancelled step', function (): void {
    $blockUuid = 'cancel-cascade-'.uniqid();

    makeBlockStep($blockUuid, 1, Completed::class);
    makeBlockStep($blockUuid, 2, Cancelled::class);
    $successor = makeBlockStep($blockUuid, 3, Pending::class);

    StepDispatcher::dispatch('test-group');

    $successor->refresh();
    expect($successor->state)->toBeInstanceOf(Cancelled::class);
});

it('does not cancel steps in unrelated blocks', function (): void {
    $cancelledBlock = 'cancel-cascade-'.uniqid();
    makeBlockStep($cancelledBlock, 1, Cancelled::class);
    $wedgedSuccessor = makeBlockStep($cancelledBlock, 2, Pending::class);

    $healthyBlock = 'healthy-block-'.uniqid();
    $healthyStep = makeBlockStep($healthyBlock, 1, Pending::class);

    StepDispatcher::dispatch('test-group');

    $wedgedSuccessor->refresh();
    $healthyStep->refresh();

    expect($wedgedSuccessor->state)->toBeInstanceOf(Cancelled::class)
        ->and($healthyStep->state)->not->toBeInstanceOf(Cancelled::class);
});
