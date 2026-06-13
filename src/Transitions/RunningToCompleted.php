<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use Spatie\ModelStates\Transition;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;

final class RunningToCompleted extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    /**
     * Prevent parent steps from completing early: a parent may only
     * complete once all its child steps are concluded. Living here (not
     * as a silent early-return in handle()) keeps the state machine
     * honest — an attempt against an unconcluded parent throws
     * CouldNotPerformTransition instead of no-opping while the framework
     * still fires StateChanged. Callers that legitimately race the
     * children (the job lifecycle) check the same rule before attempting.
     */
    public function canTransition(): bool
    {
        return ! $this->step->isParent() || $this->step->childStepsAreConcluded();
    }

    public function handle(): Step
    {
        $this->step->state = new Completed($this->step);
        $this->step->completed_at = now();
        $this->step->is_throttled = false; // Clear throttle flag - step is no longer waiting
        $this->step->save();

        Step::log($this->step->id, 'states', sprintf(
            'Running → Completed | duration_ms=%s',
            $this->step->duration !== null ? (string) $this->step->duration : 'n/a'
        ));

        return $this->step;
    }
}
