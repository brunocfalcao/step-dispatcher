<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\StepDispatcher;

/*
|--------------------------------------------------------------------------
| Child block cycles
|--------------------------------------------------------------------------
|
| Nothing in the schema stops a step pointing at a child block that leads back
| to itself. Bad data like that must not exhaust memory and take down the tick
| for every other workflow: the walk looks at each reachable step once.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function cycleStep(string $block, string $state, ?string $childBlock = null): Step
{
    $step = Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'block_uuid' => $block,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'cycle-test',
        'state' => Pending::class,
    ]);

    DB::table(Step::tableName())->where('id', $step->id)->update([
        'state' => $state,
        'child_block_uuid' => $childBlock,
    ]);

    return $step->fresh();
}

/**
 * @return array{0: Step, 1: string}
 */
function parentOverCycle(string $shape): array
{
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();

    $parent = cycleStep((string) Str::uuid(), Running::class, $first);

    if ($shape === 'self') {
        cycleStep($first, Completed::class, $first);

        return [$parent, $first];
    }

    cycleStep($first, Completed::class, $second);
    cycleStep($second, Completed::class, $first);

    return [$parent, $second];
}

function concludedThroughMap(Step $parent): bool
{
    $map = Step::query()->where('group', 'cycle-test')->get()->groupBy('block_uuid');

    return $parent->childStepsAreConcludedFromMap($map, forCompletion: true);
}

dataset('cycle shapes', [
    'a step whose child block is its own block' => ['self'],
    'two blocks leading back to each other' => ['pair'],
]);

it('treats a cycle of finished steps as concluded', function (string $shape): void {
    [$parent] = parentOverCycle($shape);

    expect($parent->childStepsAreConcluded(forCompletion: true))->toBeTrue()
        ->and(concludedThroughMap($parent))->toBeTrue();
})->with('cycle shapes');

it('still waits for an unfinished step inside a cycle', function (string $shape): void {
    [$parent, $lastBlock] = parentOverCycle($shape);
    cycleStep($lastBlock, Running::class);

    expect($parent->childStepsAreConcluded(forCompletion: true))->toBeFalse()
        ->and(concludedThroughMap($parent))->toBeFalse();
})->with('cycle shapes');

it('lets the completion sweep finish a parent over a cycle', function (string $shape): void {
    [$parent] = parentOverCycle($shape);

    StepDispatcher::transitionParentsToComplete('cycle-test');

    expect($parent->fresh()->state)->toBeInstanceOf(Completed::class);
})->with('cycle shapes');

it('checks a child block reached twice without a cycle', function (): void {
    $shared = (string) Str::uuid();
    $middle = (string) Str::uuid();
    $parent = cycleStep((string) Str::uuid(), Running::class, $middle);

    cycleStep($middle, Completed::class, $shared);
    cycleStep($middle, Completed::class, $shared);
    cycleStep($shared, Completed::class);

    expect($parent->childStepsAreConcluded(forCompletion: true))->toBeTrue()
        ->and(concludedThroughMap($parent))->toBeTrue();

    cycleStep($shared, Running::class);

    expect($parent->childStepsAreConcluded(forCompletion: true))->toBeFalse()
        ->and(concludedThroughMap($parent))->toBeFalse();
});
