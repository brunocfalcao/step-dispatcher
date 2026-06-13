<?php

declare(strict_types=1);

namespace StepDispatcher\Support;

/**
 * Small timing helpers shared by the job, the dispatcher, and the tick
 * recorder — so the elapsed-millisecond calculation lives in one place
 * instead of being re-derived (with subtly different rounding) at each
 * site.
 */
final class Timing
{
    /**
     * Whole milliseconds elapsed since a `microtime(true)` start marker.
     * Clamped to zero so a backwards clock step never yields a negative
     * or absurd duration.
     */
    public static function elapsedMs(float $startMicrotime): int
    {
        return max(0, (int) round((microtime(true) - $startMicrotime) * 1000));
    }
}
