<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use StepDispatcher\Events\StaleStepsDetected;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function makeParentRecoveryStep(?string $childBlockUuid): Step
{
    $step = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => 'recovery-parent-'.Str::uuid(),
        'child_block_uuid' => $childBlockUuid,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'recovery-parent-test',
        'state' => Pending::class,
    ]);

    Step::withoutEvents(static function () use ($step): void {
        Step::whereKey($step->id)->update([
            'state' => Running::class,
            'started_at' => now()->subMinutes(10),
            'retries' => 0,
        ]);
    });

    return $step;
}

function makeParentRecoveryChild(string $blockUuid, string $state): Step
{
    $child = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'recovery-parent-test',
        'state' => Pending::class,
    ]);

    Step::withoutEvents(static function () use ($child, $state): void {
        Step::whereKey($child->id)->update(['state' => $state]);
    });

    return $child;
}

it('does not requeue a stale parent whose child block is fully terminal', function (): void {
    $childBlockUuid = 'terminal-child-'.Str::uuid();
    $parent = makeParentRecoveryStep($childBlockUuid);
    $child = makeParentRecoveryChild($childBlockUuid, Completed::class);

    expect($parent->fresh()->state)->toBeInstanceOf(Running::class)
        ->and((int) $parent->fresh()->retries)->toBe(0)
        ->and($child->fresh()->state)->toBeInstanceOf(Completed::class);

    Artisan::call('steps:recover-stale');

    expect($parent->fresh()->state)->toBeInstanceOf(Running::class)
        ->and((int) $parent->fresh()->retries)->toBe(0)
        ->and($child->fresh()->state)->toBeInstanceOf(Completed::class);

    expect(StepDispatcher::transitionParentsToComplete('recovery-parent-test'))->toBeTrue()
        ->and($parent->fresh()->state)->toBeInstanceOf(Completed::class)
        ->and($child->fresh()->state)->toBeInstanceOf(Completed::class);
});

it('does not requeue a stale parent while a child remains active', function (): void {
    $childBlockUuid = 'active-child-'.Str::uuid();
    $parent = makeParentRecoveryStep($childBlockUuid);
    $child = makeParentRecoveryChild($childBlockUuid, Pending::class);

    Artisan::call('steps:recover-stale');

    expect($parent->fresh()->state)->toBeInstanceOf(Running::class)
        ->and((int) $parent->fresh()->retries)->toBe(0)
        ->and($child->fresh()->state)->toBeInstanceOf(Pending::class);
});

it('recovers a stale parent when its elected child block is empty', function (): void {
    $childBlockUuid = 'empty-child-'.Str::uuid();
    $parent = makeParentRecoveryStep($childBlockUuid);

    expect(Step::where('block_uuid', $childBlockUuid)->exists())->toBeFalse()
        ->and($parent->fresh()->state)->toBeInstanceOf(Running::class)
        ->and((int) $parent->fresh()->retries)->toBe(0);

    Artisan::call('steps:recover-stale');

    expect(Step::where('block_uuid', $childBlockUuid)->exists())->toBeFalse()
        ->and($parent->fresh()->state)->toBeInstanceOf(Pending::class)
        ->and((int) $parent->fresh()->retries)->toBe(1);
});

it('reports the oldest step that was actually recovered', function (): void {
    Event::fake([StaleStepsDetected::class]);

    $childBlockUuid = 'settled-child-'.Str::uuid();
    $protectedParent = makeParentRecoveryStep($childBlockUuid);
    makeParentRecoveryChild($childBlockUuid, Completed::class);
    $recoveredLeaf = makeParentRecoveryStep(null);

    Artisan::call('steps:recover-stale');

    expect($protectedParent->fresh()->state)->toBeInstanceOf(Running::class)
        ->and($recoveredLeaf->fresh()->state)->toBeInstanceOf(Pending::class);

    Event::assertDispatched(
        StaleStepsDetected::class,
        fn (StaleStepsDetected $event): bool => $event->reason === 'stale_running_steps_recovered'
            && $event->oldestStep?->is($recoveredLeaf) === true,
    );
});
