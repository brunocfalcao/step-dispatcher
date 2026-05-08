<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Support\StepDispatcher as StepDispatcherSupport;
use StepDispatcher\Support\Steps;

/**
 * Two prefixed dispatchers must never share state via cache keys
 * or the active.flag file. A calc dispatcher going idle must NOT
 * deactivate the trading dispatcher's flag; a calc tick claim must
 * NOT stomp the trading tick id under the same group name.
 */
beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir().'/sd-prefix-flag-test-'.bin2hex(random_bytes(4)));
    Cache::flush();

    // Both prefixes installed so startDispatch can write its
    // dispatcher row + tick row through Eloquent without falling
    // back to the default table.
    $this->artisan('steps:install', ['--prefix' => 'trading'])->assertSuccessful();
    $this->artisan('steps:install', ['--prefix' => 'calc'])->assertSuccessful();
});

it('startDispatch under different prefixes writes distinct cache keys for the same group', function () {
    Steps::usingPrefix('trading', function (): void {
        StepsDispatcher::startDispatch('alpha');
    });

    Steps::usingPrefix('calc', function (): void {
        StepsDispatcher::startDispatch('alpha');
    });

    expect(Cache::has('current_tick_id:trading_alpha'))->toBeTrue(
        'Trading dispatcher must own a distinct cache key. Without '
        .'the prefix component the calc tick id below would have '
        .'overwritten this entry — silent cross-tier data corruption.'
    );

    expect(Cache::has('current_tick_id:calc_alpha'))->toBeTrue(
        'Calc dispatcher gets its own key because the cache key '
        .'embeds the runtime prefix.'
    );

    expect(Cache::has('current_tick_id:alpha'))->toBeFalse(
        'No prefixed dispatcher should ever write to the legacy '
        .'unprefixed key — that namespace belongs to default-prefix '
        .'hosts (existing single-dispatcher installs).'
    );
});

it('endDispatch clears only the cache keys it own', function () {
    Steps::usingPrefix('trading', function (): void {
        StepsDispatcher::startDispatch('alpha');
    });

    Steps::usingPrefix('calc', function (): void {
        StepsDispatcher::startDispatch('alpha');
    });

    Steps::usingPrefix('trading', function (): void {
        StepsDispatcher::endDispatch(8, 'alpha');
    });

    expect(Cache::has('current_tick_id:trading_alpha'))->toBeFalse(
        'Trading endDispatch cleared its own key.'
    );

    expect(Cache::has('current_tick_id:calc_alpha'))->toBeTrue(
        'Calc dispatcher tick is still in flight — its cache key '
        .'must remain untouched. A cross-prefix forget() would be '
        .'a regression.'
    );
});

it('activate/deactivate use per-prefix flag file paths', function () {
    Steps::usingPrefix('trading', function (): void {
        StepDispatcherSupport::activate();
    });

    expect(Steps::usingPrefix('trading', fn () => StepDispatcherSupport::isActive()))->toBeTrue();
    expect(Steps::usingPrefix('calc', fn () => StepDispatcherSupport::isActive()))->toBeFalse(
        'Trading activated its own flag file (`trading_active.flag`), '
        .'not the shared `active.flag`. Calc dispatcher should NOT '
        .'see itself as active just because trading is.'
    );
    expect(StepDispatcherSupport::isActive())->toBeFalse(
        'And the default (unprefixed) dispatcher must also report '
        .'inactive — its flag is `active.flag`, not touched by trading.'
    );
});

it('one prefix going idle does not deactivate another prefix flag', function () {
    Steps::usingPrefix('trading', function (): void {
        StepDispatcherSupport::activate();
    });
    Steps::usingPrefix('calc', function (): void {
        StepDispatcherSupport::activate();
    });

    Steps::usingPrefix('calc', function (): void {
        StepDispatcherSupport::deactivate();
    });

    expect(Steps::usingPrefix('trading', fn () => StepDispatcherSupport::isActive()))->toBeTrue(
        'Calc deactivation must not unlink the trading flag file. '
        .'Without per-prefix paths, the package previously shared '
        .'a single `active.flag` and a calc-side deactivate would '
        .'silently turn off trading.'
    );

    expect(Steps::usingPrefix('calc', fn () => StepDispatcherSupport::isActive()))->toBeFalse();
});
