<?php

declare(strict_types=1);

use StepDispatcher\Models\Step;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

it('leaves the executable job class explicit in the package factory', function (): void {
    $step = Step::factory()->make();

    expect($step->class)->toBeNull()
        ->and($step->queue)->toBe('default');
});

it('accepts an executable consumer job override in the package factory', function (): void {
    $step = Step::factory()->make([
        'class' => PrefixCarryingTestJob::class,
        'queue' => 'default',
    ]);

    expect($step->class)->toBe(PrefixCarryingTestJob::class)
        ->and($step->queue)->toBe('default');
});
