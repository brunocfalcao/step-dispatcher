<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\StepDispatcher;

/*
|--------------------------------------------------------------------------
| Dormant resolver keeps the dispatcher awake
|--------------------------------------------------------------------------
|
| A block's resolve-exception step stays NotRunnable until a tick promotes
| it, and promotion is a cleanup phase that runs on a later tick than the
| one that failed the block. If the idle check only counts Pending,
| Dispatched and Running rows, the tick that fails the last ordinary step
| sees nothing active and removes the flag. The resolver is then never
| promoted until unrelated work wakes the dispatcher, and the workflow it
| was meant to close stays open (production, 2026-10-22).
|
*/

beforeEach(function (): void {
    $this->flagRoot = sys_get_temp_dir().'/step-dispatcher-dormant-'.bin2hex(random_bytes(8));
    config()->set('step-dispatcher.flag_path', $this->flagRoot);
});

afterEach(function (): void {
    File::deleteDirectory($this->flagRoot);
});

function makeFailedBlockWithDormantResolver(?string $failedGroup = 'kappa', ?string $resolverGroup = 'kappa'): Step
{
    $blockUuid = (string) Str::uuid();

    $failed = Step::create(['block_uuid' => $blockUuid, 'index' => 1, 'group' => $failedGroup]);
    $resolver = Step::create(['block_uuid' => $blockUuid, 'index' => 1, 'type' => 'resolve-exception', 'group' => $resolverGroup]);

    DB::table(Step::tableName())->where('id', $failed->id)->update(['state' => Failed::class]);
    DB::table(Step::tableName())->where('id', $resolver->id)->update(['state' => NotRunnable::class]);

    return $resolver;
}

it('counts a dormant resolver in a failed block as active work', function (): void {
    makeFailedBlockWithDormantResolver();

    expect(StepDispatcher::hasActiveSteps())->toBeTrue();
});

it('stays awake when the tick fails the parent of a dormant resolver', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = Step::create(['block_uuid' => $parentBlock, 'index' => 1, 'group' => 'kappa', 'child_block_uuid' => $childBlock]);
    $resolver = Step::create(['block_uuid' => $parentBlock, 'index' => 1, 'type' => 'resolve-exception', 'group' => 'kappa']);
    $child = Step::create(['block_uuid' => $childBlock, 'index' => 1, 'group' => 'kappa']);

    DB::table(Step::tableName())->where('id', $parent->id)->update(['state' => Running::class]);
    DB::table(Step::tableName())->where('id', $resolver->id)->update(['state' => NotRunnable::class]);
    DB::table(Step::tableName())->where('id', $child->id)->update(['state' => Failed::class]);
    StepDispatcher::activate();

    StepDispatcher::dispatch('kappa');

    expect($parent->fresh()->state)->toBeInstanceOf(Failed::class)
        ->and(StepDispatcher::isActive())->toBeTrue();

    StepDispatcher::dispatch('kappa');

    expect($resolver->fresh()->state)->toBeInstanceOf(Pending::class)
        ->and(StepDispatcher::isActive())->toBeTrue();
});

it('does not count a dormant resolver whose block has no failure', function (): void {
    $blockUuid = (string) Str::uuid();
    $done = Step::create(['block_uuid' => $blockUuid, 'index' => 1]);
    $resolver = Step::create(['block_uuid' => $blockUuid, 'index' => 1, 'type' => 'resolve-exception']);
    DB::table(Step::tableName())->where('id', $done->id)->update(['state' => Completed::class]);
    DB::table(Step::tableName())->where('id', $resolver->id)->update(['state' => NotRunnable::class]);

    expect(StepDispatcher::hasActiveSteps())->toBeFalse();
});

it('does not count a dormant resolver that no group tick can promote', function (): void {
    makeFailedBlockWithDormantResolver(failedGroup: 'kappa', resolverGroup: 'alpha');

    expect(StepDispatcher::hasActiveSteps())->toBeFalse();
});

it('lets the dispatcher sleep once the promoted resolver has finished', function (): void {
    $resolver = makeFailedBlockWithDormantResolver();
    DB::table(Step::tableName())->where('id', $resolver->id)->update(['state' => Completed::class]);
    StepDispatcher::activate();

    StepDispatcher::dispatch('kappa');

    expect(StepDispatcher::isActive())->toBeFalse();
});

it('wakes a sleeping dispatcher that still owns work on the next stale sweep', function (): void {
    Step::create(['block_uuid' => (string) Str::uuid(), 'index' => 1, 'group' => 'kappa']);
    StepDispatcher::deactivate();

    $this->artisan('steps:recover-stale')->assertSuccessful();

    expect(StepDispatcher::isActive())->toBeTrue();
});

it('leaves an idle dispatcher asleep on the stale sweep', function (): void {
    StepDispatcher::deactivate();

    $this->artisan('steps:recover-stale')->assertSuccessful();

    expect(StepDispatcher::isActive())->toBeFalse();
});
