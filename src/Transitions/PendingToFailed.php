<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use Spatie\ModelStates\Transition;

final class PendingToFailed extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        // Only allow transition if the current state is Pending
        if (! ($this->step->state instanceof Pending)) {
            return false;
        }

        return true;
    }

    public function handle(): Step
    {
        // Transition to Failed state
        $this->step->state = new Failed($this->step);
        $this->step->completed_at = now();
        $this->step->is_throttled = false; // Clear throttle flag - step is no longer waiting
        $this->step->save(); // Save the step after state transition

        Step::log($this->step->id, 'states', sprintf(
            'Pending → Failed | error=%s',
            $this->step->error_message ? mb_substr((string) $this->step->error_message, 0, 200) : 'n/a'
        ));

        // Return the step for further processing if needed
        return $this->step;
    }
}
