<?php

declare(strict_types=1);

use StepDispatcher\Models\Step;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;

/*
|--------------------------------------------------------------------------
| StepObserver Creation Defaults
|--------------------------------------------------------------------------
|
| Every step is normalised at creation by the observer: a block_uuid is
| minted if absent, index null/0 collapses to 1 (so index-1 steps run in
| parallel), resolve-exception steps are born NotRunnable (parked until a
| failure promotes them), priority=high auto-routes to the priority queue,
| an invalid queue falls back to default, and a workflow_id is assigned.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

it('mints a block_uuid when none is provided', function (): void {
    $step = Step::create([
        'class' => 'App\\EchoJob',
        'type' => 'default',
        'queue' => 'default',
        'state' => Pending::class,
    ]);

    expect($step->block_uuid)->not->toBeNull()
        ->and(mb_strlen($step->block_uuid))->toBe(36);
});

it('collapses a null or zero index to 1', function (): void {
    $nullIndex = Step::create([
        'class' => 'App\\EchoJob', 'type' => 'default', 'queue' => 'default',
        'block_uuid' => 'b1-'.uniqid(), 'index' => null, 'state' => Pending::class,
    ]);
    $zeroIndex = Step::create([
        'class' => 'App\\EchoJob', 'type' => 'default', 'queue' => 'default',
        'block_uuid' => 'b2-'.uniqid(), 'index' => 0, 'state' => Pending::class,
    ]);

    expect($nullIndex->index)->toBe(1)
        ->and($zeroIndex->index)->toBe(1);
});

it('stamps a resolve-exception step as NotRunnable on creation', function (): void {
    $step = Step::create([
        'class' => 'App\\EchoJob', 'type' => 'resolve-exception', 'queue' => 'default',
        'block_uuid' => 'rx-'.uniqid(), 'index' => null,
    ]);

    expect($step->state)->toBeInstanceOf(NotRunnable::class);
});

it('auto-routes a high-priority step to the priority queue', function (): void {
    $step = Step::create([
        'class' => 'App\\EchoJob', 'type' => 'default',
        'block_uuid' => 'pri-'.uniqid(), 'priority' => 'high', 'state' => Pending::class,
    ]);

    expect($step->queue)->toBe('priority');
});

it('falls back to the default queue when an invalid queue is supplied', function (): void {
    $step = Step::create([
        'class' => 'App\\EchoJob', 'type' => 'default',
        'block_uuid' => 'q-'.uniqid(), 'queue' => 'not-a-real-queue', 'state' => Pending::class,
    ]);

    expect($step->queue)->toBe('default');
});

it('assigns a workflow_id when none is provided', function (): void {
    $step = Step::create([
        'class' => 'App\\EchoJob', 'type' => 'default', 'queue' => 'default',
        'block_uuid' => 'wf-'.uniqid(), 'state' => Pending::class,
    ]);

    expect($step->workflow_id)->not->toBeNull();
});

it('preserves an explicitly-set valid queue on a high-priority step', function (): void {
    config()->set('step-dispatcher.queues.valid', ['ares']);

    $step = Step::create([
        'class' => 'App\\EchoJob', 'type' => 'default',
        'block_uuid' => 'pq-'.uniqid(), 'priority' => 'high', 'queue' => 'ares', 'state' => Pending::class,
    ]);

    expect($step->queue)->toBe('ares')
        ->and($step->priority)->toBe('high');
});

it('routes a high-priority step with an invalid queue to the priority lane, not default', function (): void {
    $step = Step::create([
        'class' => 'App\\EchoJob', 'type' => 'default',
        'block_uuid' => 'pq-'.uniqid(), 'priority' => 'high', 'queue' => 'not-a-real-queue', 'state' => Pending::class,
    ]);

    expect($step->queue)->toBe('priority');
});

it('inherits workflow ownership from the parent unless the child sets its own owner', function (): void {
    $parent = Step::create([
        'class' => 'App\\EchoJob',
        'type' => 'default',
        'queue' => 'default',
        'child_block_uuid' => 'children-'.uniqid(),
        'relatable_type' => 'App\\Models\\KnowledgeBase',
        'relatable_id' => 41,
        'state' => Pending::class,
    ]);

    $inherited = Step::create([
        'class' => 'App\\EchoJob',
        'type' => 'default',
        'queue' => 'default',
        'block_uuid' => $parent->child_block_uuid,
        'state' => Pending::class,
    ]);

    $explicit = Step::create([
        'class' => 'App\\EchoJob',
        'type' => 'default',
        'queue' => 'default',
        'block_uuid' => $parent->child_block_uuid,
        'relatable_type' => 'App\\Models\\Source',
        'relatable_id' => 99,
        'state' => Pending::class,
    ]);

    expect($inherited->relatable_type)->toBe('App\\Models\\KnowledgeBase')
        ->and($inherited->relatable_id)->toBe(41)
        ->and($explicit->relatable_type)->toBe('App\\Models\\Source')
        ->and($explicit->relatable_id)->toBe(99);
});

it('repairs an incomplete child owner from its complete parent owner', function (array $incompleteOwner): void {
    $parent = Step::create([
        'class' => 'App\\EchoJob',
        'type' => 'default',
        'queue' => 'default',
        'child_block_uuid' => 'children-'.uniqid(),
        'relatable_type' => 'App\\Models\\KnowledgeBase',
        'relatable_id' => 41,
        'state' => Pending::class,
    ]);

    $child = Step::create([
        'class' => 'App\\EchoJob',
        'type' => 'default',
        'queue' => 'default',
        'block_uuid' => $parent->child_block_uuid,
        'state' => Pending::class,
        ...$incompleteOwner,
    ]);

    expect($child->relatable_type)->toBe('App\\Models\\KnowledgeBase')
        ->and($child->relatable_id)->toBe(41);
})->with([
    'type without id' => [['relatable_type' => 'App\\Models\\Source']],
    'id without type' => [['relatable_id' => 99]],
    'empty type with id' => [['relatable_type' => '', 'relatable_id' => 99]],
    'type with zero id' => [['relatable_type' => 'App\\Models\\Source', 'relatable_id' => 0]],
]);
