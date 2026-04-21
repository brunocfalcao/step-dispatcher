<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Skipped;
use Spatie\ModelStates\Transition;

final class PendingToSkipped extends Transition
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
        // Transition to Skipped state
        $this->step->state = new Skipped($this->step);
        $this->step->is_throttled = false; // Clear throttle flag - step is no longer waiting
        $this->step->save(); // Save the transition

        Step::log($this->step->id, 'states', 'Pending → Skipped');

        return $this->step;
    }
}
