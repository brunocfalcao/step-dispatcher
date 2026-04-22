<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use Spatie\ModelStates\Transition;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;

/**
 * Dispatched → Pending.
 *
 * Fired by `steps:recover-stale --recover-dispatched` when a Dispatched step
 * is orphaned: its Redis payload was popped by a worker that died before it
 * could advance the step to Running. Flipping the state back to Pending lets
 * the next dispatcher tick re-push the step to its queue and get a new worker
 * to pick it up.
 *
 * Duplicate-execution risk (a legit Redis payload still in flight while we
 * re-queue) is absorbed by `BaseStepJob::prepareJobExecution()`, which bails
 * when it sees the step already in Running state.
 */
final class DispatchedToPending extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        // Only allow transition if the current state is Dispatched
        if (! ($this->step->state instanceof Dispatched)) {
            return false;
        }

        return true;
    }

    public function handle(): Step
    {
        // This is a genuine retry attempt — the step was orphaned mid-flight.
        // Increment retries so the job's max-retry budget reflects the truth,
        // unless the step is currently in a throttled-waiting window (same
        // convention as RunningToPending).
        if (! $this->step->is_throttled) {
            $this->step->increment('retries');
        }

        // Reset timers so downstream logic (stale detector, duration metric)
        // treats this as a fresh attempt rather than the orphan that led here.
        $this->step->started_at = null;
        $this->step->completed_at = null;
        $this->step->duration = 0;

        // Reset the state to Pending
        $this->step->state = new Pending($this->step);
        $this->step->save();

        Step::log($this->step->id, 'states', sprintf(
            'Dispatched → Pending | retries=%d | is_throttled=%s | dispatch_after=%s',
            (int) $this->step->retries,
            $this->step->is_throttled ? 'true' : 'false',
            $this->step->dispatch_after ? $this->step->dispatch_after->format('H:i:s.u') : 'null'
        ));

        return $this->step;
    }
}
