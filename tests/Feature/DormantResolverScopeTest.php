<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Support\Steps;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

beforeEach(function (): void {
    $this->scopeFlagRoot = sys_get_temp_dir().'/dispatcher-resolver-scope-'.bin2hex(random_bytes(8));
    config()->set('step-dispatcher.flag_path', $this->scopeFlagRoot);
});

afterEach(function (): void {
    File::deleteDirectory($this->scopeFlagRoot);
});

function seedDormantScopeResolver(string $failedState): array
{
    $block = (string) Str::uuid();
    $failed = Step::create(['block_uuid' => $block, 'index' => 1, 'group' => 'kappa']);
    $resolver = Step::create(['block_uuid' => $block, 'index' => 1, 'type' => 'resolve-exception', 'group' => 'kappa']);
    DB::table(Step::tableName())->where('id', $failed->id)->update(['state' => $failedState, 'group' => null]);
    DB::table(Step::tableName())->where('id', $resolver->id)->update(['state' => NotRunnable::class, 'group' => null]);

    return [$failed, $resolver];
}

it('keeps global failure resolvers awake and promotes them without changing the failed step', function (string $failedState): void {
    [$failed, $resolver] = seedDormantScopeResolver($failedState);

    expect($resolver->fresh()->state)->toBeInstanceOf(NotRunnable::class)
        ->and(StepDispatcher::hasActiveSteps())->toBeTrue();

    StepDispatcher::dispatch();

    expect($resolver->fresh()->state)->toBeInstanceOf(Pending::class)
        ->and($failed->fresh()->state)->toBeInstanceOf($failedState)
        ->and(StepDispatcher::isActive())->toBeTrue();
})->with([Failed::class, Stopped::class]);

it('recovers a prefixed dormant resolver without waking the default prefix', function (): void {
    $this->artisan('steps:install', ['--prefix' => 'recovery'])->assertSuccessful();
    $resolverId = Steps::usingPrefix('recovery', function (): int {
        [, $resolver] = seedDormantScopeResolver(Failed::class);
        StepDispatcher::deactivate();

        return $resolver->id;
    });

    expect(StepDispatcher::hasActiveSteps())->toBeFalse()
        ->and(Steps::usingPrefix('recovery', fn (): bool => StepDispatcher::isActive()))->toBeFalse();

    $this->artisan('steps:recover-stale', ['--prefix' => 'recovery'])->assertSuccessful();

    expect(StepDispatcher::isActive())->toBeFalse()
        ->and(StepDispatcher::hasActiveSteps())->toBeFalse()
        ->and(Steps::usingPrefix('recovery', fn (): bool => StepDispatcher::isActive()))->toBeTrue()
        ->and(Steps::usingPrefix('recovery', fn (): Step => Step::findOrFail($resolverId))->state)->toBeInstanceOf(NotRunnable::class);
});

it('recovers a stale running row only in the selected prefix', function (): void {
    $default = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
        'group' => 'default-stale',
    ]);
    DB::table(Step::tableName())->where('id', $default->id)->update([
        'state' => Running::class,
        'started_at' => now()->subMinutes(10),
    ]);

    $this->artisan('steps:install', ['--prefix' => 'recovery'])->assertSuccessful();
    $recoveryId = Steps::usingPrefix('recovery', function (): int {
        $step = Step::create([
            'class' => PrefixCarryingTestJob::class,
            'block_uuid' => (string) Str::uuid(),
            'index' => 1,
            'group' => 'recovery-stale',
        ]);
        DB::table(Step::tableName())->where('id', $step->id)->update([
            'state' => Running::class,
            'started_at' => now()->subMinutes(10),
        ]);

        return $step->id;
    });

    $this->artisan('steps:recover-stale', ['--prefix' => 'recovery'])->assertSuccessful();

    $recovered = Steps::usingPrefix('recovery', fn (): Step => Step::findOrFail($recoveryId));
    expect($recovered->state)->toBeInstanceOf(Pending::class)
        ->and((int) $recovered->retries)->toBe(1)
        ->and($default->fresh()->state)->toBeInstanceOf(Running::class)
        ->and((int) $default->fresh()->retries)->toBe(0);
});
