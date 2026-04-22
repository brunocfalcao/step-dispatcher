<?php

declare(strict_types=1);

namespace StepDispatcher\Events;

use Illuminate\Foundation\Events\Dispatchable;
use StepDispatcher\Models\Step;

/**
 * Fired by RecoverStaleStepsCommand whenever the dispatcher surfaces a stall
 * condition that required — or still needs — intervention.
 *
 * Consumers decide how to react (pushover, Slack, email, Sentry breadcrumb,
 * silent log). The package itself never notifies anyone.
 *
 * Severity:
 *   - 'warning':  stall was detected and self-healed in-band (dispatched steps
 *                 promoted, or wedged locks released).
 *   - 'critical': self-healing already ran in a previous cycle and the stall
 *                 is still present — human attention likely needed.
 *
 * Reason discriminates which flavour of stall (so consumers can route to the
 * right message template). Not an enum on purpose: callers can add new reasons
 * without forcing a package bump.
 *
 *   - 'stale_running_steps_recovered'       — zombie Running step flipped back
 *                                              to Pending or failed.
 *   - 'stale_dispatched_steps_promoted'     — stuck Dispatched step(s) pushed
 *                                              to priority queue.
 *   - 'stale_dispatched_steps_still_stuck'  — promotion already happened and
 *                                              the step(s) remain Dispatched.
 *   - 'stale_dispatcher_locks_released'     — one or more group locks were
 *                                              force-unlocked.
 */
final class StaleStepsDetected
{
    use Dispatchable;

    /**
     * @param  'warning'|'critical'  $severity
     * @param  non-empty-string  $reason
     * @param  array<string, mixed>  $context  Free-form extra info (release count,
     *                                         threshold values, group, etc.).
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $reason,
        public readonly int $count,
        public readonly int $alreadyPromotedCount = 0,
        public readonly int $promotedCount = 0,
        public readonly int $releasedLocksCount = 0,
        public readonly ?Step $oldestStep = null,
        public readonly array $context = [],
    ) {}
}
