<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Tests\Fixtures\ConfiguredTimeoutTestJob;

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    ConfiguredTimeoutTestJob::$runtimeTimeout = null;
});

afterEach(function (): void {
    ConfiguredTimeoutTestJob::$runtimeTimeout = null;
});

function makeConfiguredTimeoutStep(int $secondsAgo): Step
{
    $step = Step::create([
        'class' => ConfiguredTimeoutTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'configured-timeout-'.Str::uuid(),
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ]);

    Step::withoutEvents(static function () use ($step, $secondsAgo): void {
        Step::query()->whereKey($step->id)->update([
            'state' => Running::class,
            'started_at' => now()->subSeconds($secondsAgo),
            'retries' => 0,
        ]);
    });

    return $step->fresh();
}

function ageConfiguredTimeoutStep(Step $step, int $secondsAgo): void
{
    Step::withoutEvents(static function () use ($step, $secondsAgo): void {
        Step::query()->whereKey($step->id)->update([
            'state' => Running::class,
            'started_at' => now()->subSeconds($secondsAgo),
            'retries' => 0,
        ]);
    });
}

it('uses a larger positive runtime timeout in the stale recovery lifecycle', function (): void {
    ConfiguredTimeoutTestJob::$runtimeTimeout = 2400;
    $step = makeConfiguredTimeoutStep(2459);

    expect($step->fresh()->state)->toBeInstanceOf(Running::class)
        ->and($step->fresh()->retries)->toBe(0);

    Artisan::call('steps:recover-stale');

    expect($step->fresh()->state)->toBeInstanceOf(Running::class)
        ->and($step->fresh()->retries)->toBe(0);

    ageConfiguredTimeoutStep($step, 2461);
    Artisan::call('steps:recover-stale');

    $recovered = $step->fresh();
    expect($recovered->state)->toBeInstanceOf(Pending::class)
        ->and($recovered->retries)->toBe(1)
        ->and($recovered->error_message)->toContain('threshold: 2460s');
});

it('uses a shorter positive runtime timeout instead of the reflected default', function (): void {
    ConfiguredTimeoutTestJob::$runtimeTimeout = 60;
    $step = makeConfiguredTimeoutStep(119);

    Artisan::call('steps:recover-stale');

    expect($step->fresh()->state)->toBeInstanceOf(Running::class)
        ->and($step->fresh()->retries)->toBe(0);

    ageConfiguredTimeoutStep($step, 121);
    Artisan::call('steps:recover-stale');

    $recovered = $step->fresh();
    expect($recovered->state)->toBeInstanceOf(Pending::class)
        ->and($recovered->retries)->toBe(1)
        ->and($recovered->error_message)->toContain('threshold: 120s');
});

it('falls back to the reflected timeout when the runtime override is null', function (): void {
    ConfiguredTimeoutTestJob::$runtimeTimeout = null;
    $step = makeConfiguredTimeoutStep(1859);

    Artisan::call('steps:recover-stale');

    expect($step->fresh()->state)->toBeInstanceOf(Running::class)
        ->and($step->fresh()->retries)->toBe(0);

    ageConfiguredTimeoutStep($step, 1861);
    Artisan::call('steps:recover-stale');

    $recovered = $step->fresh();
    expect($recovered->state)->toBeInstanceOf(Pending::class)
        ->and($recovered->retries)->toBe(1)
        ->and($recovered->error_message)->toContain('threshold: 1860s');
});

it('uses the existing fallback for nonpositive runtime overrides', function (int $runtimeTimeout): void {
    ConfiguredTimeoutTestJob::$runtimeTimeout = $runtimeTimeout;
    $step = makeConfiguredTimeoutStep(359);

    Artisan::call('steps:recover-stale');

    expect($step->fresh()->state)->toBeInstanceOf(Running::class)
        ->and($step->fresh()->retries)->toBe(0);

    ageConfiguredTimeoutStep($step, 361);
    Artisan::call('steps:recover-stale');

    $recovered = $step->fresh();
    expect($recovered->state)->toBeInstanceOf(Pending::class)
        ->and($recovered->retries)->toBe(1)
        ->and($recovered->error_message)->toContain('threshold: 360s');
})->with([0, -1]);
