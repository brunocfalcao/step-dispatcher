<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use Spatie\ModelStates\Transition;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Skipped;

/**
 * A Dispatched step can be skipped when its parent's block is being
 * swept (parent Skipped → all descendants Skipped). Without this
 * transition, a Dispatched descendant was unskippable: the sweep
 * reselected it every tick, the transition threw, and the dispatcher
 * early-returned before its dispatch phase — wedging the whole group.
 * The queued job is not a concern: when it eventually runs, its
 * prepareJobExecution bails because the step is no longer Dispatched.
 */
final class DispatchedToSkipped extends Transition
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

        Step::log($this->step->id, 'states', 'Dispatched → Skipped');

        return $this->step;
    }
}
