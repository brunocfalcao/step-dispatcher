<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use Spatie\ModelStates\Transition;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;

final class NotRunnableToCancelled extends Transition
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
        $this->step->state = new Cancelled($this->step);
        $this->step->completed_at = now();
        $this->step->save();

        Step::log($this->step->id, 'states', 'NotRunnable → Cancelled');

        return $this->step;
    }
}
