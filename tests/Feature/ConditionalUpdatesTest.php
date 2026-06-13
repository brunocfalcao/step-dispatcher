<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;

/*
|--------------------------------------------------------------------------
| HasConditionalUpdates
|--------------------------------------------------------------------------
|
| updateSaving() is fill-then-save in one call; updateIfNotSet() writes a
| column only while it is still null (the exception loggers rely on it to
| record the FIRST error and never overwrite it). The not-set path also
| fires an optional callback exactly once, only when it actually wrote.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function freshStep(): Step
{
    return Step::create([
        'class' => 'App\\EchoJob',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'cu-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ]);
}

it('fills and persists in one call via updateSaving', function (): void {
    $step = freshStep();

    $result = $step->updateSaving(['label' => 'hello']);

    expect($result)->toBeTrue()
        ->and($step->fresh()->label)->toBe('hello');
});

it('writes a null column once and refuses to overwrite via updateIfNotSet', function (): void {
    $step = freshStep();
    $callbackCount = 0;

    $first = $step->updateIfNotSet('error_message', 'first', function () use (&$callbackCount): void {
        $callbackCount++;
    });

    $second = $step->updateIfNotSet('error_message', 'second', function () use (&$callbackCount): void {
        $callbackCount++;
    });

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($step->fresh()->error_message)->toBe('first')
        ->and($callbackCount)->toBe(1);
});
