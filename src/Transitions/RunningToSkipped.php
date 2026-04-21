<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Skipped;
use Spatie\ModelStates\Transition;

final class RunningToSkipped extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        return true; // Always returns true based on your current logic
    }

    public function handle(): Step
    {
        // Transition to Skipped state
        $this->step->state = new Skipped($this->step);
        $this->step->completed_at = now();
        $this->step->is_throttled = false; // Clear throttle flag - step is no longer waiting
        $this->step->save(); // Save the transition

        Step::log($this->step->id, 'states', 'Running → Skipped');

        return $this->step;
    }
}
