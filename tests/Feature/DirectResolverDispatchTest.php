<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Transitions\PendingToDispatched;

function directResolverStep(string $block, int $index, string $state): Step
{
    $step = Step::create([
        'class' => 'StepDispatcher\\Tests\\Fixtures\\PrefixCarryingTestJob',
        'type' => 'resolve-exception',
        'queue' => 'default',
        'group' => 'resolver-test',
        'index' => $index,
        'block_uuid' => $block,
    ]);

    DB::table(Step::tableName())->where('id', $step->id)->update(['state' => $state]);

    return $step->fresh();
}

it('requires every parallel resolver in the previous stage to be concluded', function (): void {
    $block = (string) Str::uuid();
    directResolverStep($block, 1, Completed::class);
    $runningSibling = directResolverStep($block, 1, Running::class);
    $target = directResolverStep($block, 2, Pending::class);

    expect((new PendingToDispatched($target))->canTransition())->toBeFalse();

    DB::table(Step::tableName())->where('id', $runningSibling->id)->update(['state' => Completed::class]);

    expect((new PendingToDispatched($target->fresh()))->canTransition())->toBeTrue();
});
