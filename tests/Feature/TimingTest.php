<?php

declare(strict_types=1);

use StepDispatcher\Support\Timing;

/*
|--------------------------------------------------------------------------
| Timing::elapsedMs
|--------------------------------------------------------------------------
|
| One place computes elapsed milliseconds for durations and tick timing.
| It must clamp to zero so a backwards clock step (NTP correction) never
| persists a negative or absurd duration onto a step or tick.
|
*/

it('returns a positive elapsed value for a past start marker', function (): void {
    $start = microtime(true) - 1.0; // ~1s ago

    expect(Timing::elapsedMs($start))->toBeInt()
        ->and(Timing::elapsedMs($start))->toBeGreaterThanOrEqual(500);
});

it('clamps to zero when the start marker is in the future', function (): void {
    $start = microtime(true) + 100.0; // clock skew / NTP correction

    expect(Timing::elapsedMs($start))->toBe(0);
});

it('returns a near-zero non-negative value for a just-now start', function (): void {
    expect(Timing::elapsedMs(microtime(true)))->toBeGreaterThanOrEqual(0);
});
