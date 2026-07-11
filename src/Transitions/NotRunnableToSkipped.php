<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use Spatie\ModelStates\Transition;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Skipped;

/**
 * A NotRunnable step (dormant resolve-exception) is skipped when its
 * parent's block is swept after the parent was Skipped — the failure
 * path it was armed for can no longer occur. NotRunnable is not a
 * terminal state, so without this edge the sweep would reselect the
 * row on every dispatcher tick (log noise; pre-honest-return it was a
 * full group wedge, same class as the missing Dispatched → Skipped).
 */
final class NotRunnableToSkipped extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        return true;
    }

    public function handle(): Step
    {
        $this->step->state = new Skipped($this->step);
        $this->step->completed_at = now();
        $this->step->is_throttled = false;
        $this->step->save();

        Step::log($this->step->id, 'states', 'NotRunnable → Skipped');

        return $this->step;
    }
}
