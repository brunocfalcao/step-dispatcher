<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Dispatch Command Exit Code
|--------------------------------------------------------------------------
|
| Operators monitor scheduler exit codes. A tick that dies (DB gone,
| queue driver down) must exit non-zero — swallowing the Throwable and
| returning SUCCESS hides total dispatch failure from alerting.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

it('returns FAILURE when the tick throws', function (): void {
    // Activate the dispatcher so handle() reaches the dispatch loop.
    Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => 'exit-code-'.uniqid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'state' => Pending::class,
    ]);

    // A resolver that explodes outside dispatchSingleStep's own catch is
    // hard to arrange; instead break the tick at its first DB touch by
    // dropping the dispatcher table.
    Illuminate\Support\Facades\Schema::drop('steps_dispatcher');

    $exitCode = Artisan::call('steps:dispatch', ['--group' => 'test-group']);

    expect($exitCode)->toBe(1);
});

it('returns SUCCESS on a healthy tick', function (): void {
    Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => 'exit-code-'.uniqid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'state' => Pending::class,
    ]);

    $exitCode = Artisan::call('steps:dispatch', ['--group' => 'test-group']);

    expect($exitCode)->toBe(0);
});

it('returns SUCCESS when the dispatcher is idle', function (): void {
    StepDispatcher::deactivate();

    $exitCode = Artisan::call('steps:dispatch');

    expect($exitCode)->toBe(0);
});
