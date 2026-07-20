<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function countActionableCascadeQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function makeCascadeBudgetStep(
    string $blockUuid,
    int $index,
    string $state,
    string $group = 'cascade-budget-test',
    ?string $childBlockUuid = null,
): Step {
    $step = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => $blockUuid,
        'child_block_uuid' => $childBlockUuid,
        'index' => $index,
        'type' => 'default',
        'queue' => 'default',
        'group' => $group,
        'state' => Pending::class,
    ]);

    Step::withoutEvents(static function () use ($step, $state): void {
        Step::whereKey($step->id)->update(['state' => $state]);
    });

    return $step;
}

it('ignores settled failure history without issuing one successor query per row', function (): void {
    $token = (string) Str::uuid();

    foreach (range(1, 40) as $index) {
        $blockUuid = "settled-failure-{$token}-{$index}";
        makeCascadeBudgetStep($blockUuid, 1, Failed::class);
        makeCascadeBudgetStep($blockUuid, 2, Completed::class);
    }

    $healthy = makeCascadeBudgetStep("healthy-{$token}", 1, Pending::class);

    $queries = countActionableCascadeQueries(
        static fn (): bool => StepDispatcher::cascadeCancelledSteps('cascade-budget-test')
    );

    expect($queries)->toBeLessThanOrEqual(3)
        ->and($healthy->fresh()->state)->toBeInstanceOf(Pending::class);
});

it('cancels only actionable successors with a history-independent query budget', function (): void {
    $token = (string) Str::uuid();

    foreach (range(1, 40) as $index) {
        $blockUuid = "settled-action-{$token}-{$index}";
        makeCascadeBudgetStep($blockUuid, 1, Failed::class);
        makeCascadeBudgetStep($blockUuid, 2, Completed::class);
    }

    $actionableBlock = "actionable-{$token}";
    makeCascadeBudgetStep($actionableBlock, 1, Failed::class);
    $parallel = makeCascadeBudgetStep($actionableBlock, 1, Pending::class);
    $successor = makeCascadeBudgetStep($actionableBlock, 2, Pending::class);
    $healthy = makeCascadeBudgetStep("healthy-action-{$token}", 1, Pending::class);

    expect($parallel->fresh()->state)->toBeInstanceOf(Pending::class)
        ->and($successor->fresh()->state)->toBeInstanceOf(Pending::class)
        ->and($healthy->fresh()->state)->toBeInstanceOf(Pending::class);

    $queries = countActionableCascadeQueries(
        static fn (): bool => StepDispatcher::cascadeCancelledSteps('cascade-budget-test')
    );

    expect($queries)->toBeLessThanOrEqual(10)
        ->and($parallel->fresh()->state)->toBeInstanceOf(Pending::class)
        ->and($successor->fresh()->state)->toBeInstanceOf(Cancelled::class)
        ->and($healthy->fresh()->state)->toBeInstanceOf(Pending::class);
});

it('ignores settled terminal parents without querying every child block', function (): void {
    $token = (string) Str::uuid();

    foreach (range(1, 40) as $index) {
        $childBlockUuid = "settled-child-{$token}-{$index}";
        makeCascadeBudgetStep("terminal-parent-{$token}-{$index}", 1, Failed::class, childBlockUuid: $childBlockUuid);
        makeCascadeBudgetStep($childBlockUuid, 1, Completed::class);
    }

    $healthy = makeCascadeBudgetStep("healthy-parent-{$token}", 1, Pending::class);

    $queries = countActionableCascadeQueries(
        static fn (): bool => StepDispatcher::cascadeCancellationToChildren('cascade-budget-test')
    );

    expect($queries)->toBeLessThanOrEqual(3)
        ->and($healthy->fresh()->state)->toBeInstanceOf(Pending::class);
});

it('cancels only an actionable terminal parent child block', function (): void {
    $token = (string) Str::uuid();

    foreach (range(1, 40) as $index) {
        $childBlockUuid = "settled-parent-child-{$token}-{$index}";
        makeCascadeBudgetStep("settled-parent-{$token}-{$index}", 1, Failed::class, childBlockUuid: $childBlockUuid);
        makeCascadeBudgetStep($childBlockUuid, 1, Completed::class);
    }

    $actionableChildBlock = "actionable-child-{$token}";
    makeCascadeBudgetStep("actionable-parent-{$token}", 1, Failed::class, childBlockUuid: $actionableChildBlock);
    $child = makeCascadeBudgetStep($actionableChildBlock, 1, Pending::class);
    $healthy = makeCascadeBudgetStep("healthy-child-{$token}", 1, Pending::class);

    expect($child->fresh()->state)->toBeInstanceOf(Pending::class)
        ->and($healthy->fresh()->state)->toBeInstanceOf(Pending::class);

    $queries = countActionableCascadeQueries(
        static fn (): bool => StepDispatcher::cascadeCancellationToChildren('cascade-budget-test')
    );

    expect($queries)->toBeLessThanOrEqual(10)
        ->and($child->fresh()->state)->toBeInstanceOf(Cancelled::class)
        ->and($healthy->fresh()->state)->toBeInstanceOf(Pending::class);
});
