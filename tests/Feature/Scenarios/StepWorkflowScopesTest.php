<?php

declare(strict_types=1);

use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Tests\Fixtures\RelatableTestModel;

function workflowScopeStep(string $class, string $state, array $attributes = []): Step
{
    return Step::create($attributes + [
        'class' => $class,
        'queue' => 'default',
        'state' => $state,
    ]);
}

it('filters one or many workflow classes', function (): void {
    $first = workflowScopeStep('Tests\\Workflows\\First', Pending::class);
    $second = workflowScopeStep('Tests\\Workflows\\Second', Pending::class);
    workflowScopeStep('Tests\\Workflows\\Other', Pending::class);

    expect(Step::query()->forClasses('Tests\\Workflows\\First')->pluck('id')->all())
        ->toBe([$first->id])
        ->and(Step::query()
            ->forClasses(['Tests\\Workflows\\First', 'Tests\\Workflows\\Second'])
            ->orderBy('id')
            ->pluck('id')
            ->all())
        ->toBe([$first->id, $second->id]);
});

it('keeps non-terminal and in-progress state meanings distinct', function (): void {
    $states = [
        Pending::class,
        Dispatched::class,
        Running::class,
        NotRunnable::class,
        Completed::class,
        Skipped::class,
        Cancelled::class,
        Failed::class,
        Stopped::class,
    ];

    foreach ($states as $state) {
        workflowScopeStep('Tests\\Workflows\\StateProbe', $state);
    }

    expect(Step::query()->nonTerminal()->pluck('state')->map(fn ($state): string => $state::class)->sort()->values()->all())
        ->toBe(collect([
            Pending::class,
            Dispatched::class,
            Running::class,
            NotRunnable::class,
        ])->sort()->values()->all())
        ->and(Step::query()->inProgress()->pluck('state')->map(fn ($state): string => $state::class)->sort()->values()->all())
        ->toBe(collect([
            Pending::class,
            Dispatched::class,
            Running::class,
        ])->sort()->values()->all());
});

it('filters by both sides of the relatable identity', function (): void {
    $account = RelatableTestModel::withKey(41);

    $matching = workflowScopeStep('Tests\\Workflows\\Related', Pending::class, [
        'relatable_type' => $account->getMorphClass(),
        'relatable_id' => $account->getKey(),
    ]);

    workflowScopeStep('Tests\\Workflows\\Related', Pending::class, [
        'relatable_type' => 'Tests\\Models\\SomeOtherModel',
        'relatable_id' => $account->getKey(),
    ]);

    workflowScopeStep('Tests\\Workflows\\Related', Pending::class, [
        'relatable_type' => $account->getMorphClass(),
        'relatable_id' => $account->getKey() + 1,
    ]);

    expect(Step::query()->forRelatable($account)->pluck('id')->all())->toBe([$matching->id]);
});

it('reports only pending dispatched or running workflows as live', function (string $state, bool $expected): void {
    $account = RelatableTestModel::withKey(41);

    workflowScopeStep('Tests\\Workflows\\Opening', $state, [
        'relatable_type' => $account->getMorphClass(),
        'relatable_id' => $account->getKey(),
    ]);

    expect(Step::hasLiveWorkflow($account, 'Tests\\Workflows\\Opening'))->toBe($expected)
        ->and(Step::hasLiveWorkflow($account, 'Tests\\Workflows\\Different'))->toBeFalse();
})->with([
    'pending' => [Pending::class, true],
    'dispatched' => [Dispatched::class, true],
    'running' => [Running::class, true],
    'dormant resolve exception' => [NotRunnable::class, false],
    'completed' => [Completed::class, false],
    'failed' => [Failed::class, false],
]);

it('does not treat argument-only legacy rows as relational live workflows', function (): void {
    $account = RelatableTestModel::withKey(41);

    workflowScopeStep('Tests\\Workflows\\Opening', Pending::class, [
        'arguments' => ['accountId' => $account->getKey()],
    ]);

    expect(Step::hasLiveWorkflow($account, 'Tests\\Workflows\\Opening'))->toBeFalse();
});
