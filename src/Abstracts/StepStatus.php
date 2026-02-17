<?php

declare(strict_types=1);

namespace StepDispatcher\Abstracts;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Transitions\DispatchedToCancelled;
use StepDispatcher\Transitions\DispatchedToFailed;
use StepDispatcher\Transitions\DispatchedToRunning;
use StepDispatcher\Transitions\NotRunnableToPending;
use StepDispatcher\Transitions\PendingToCancelled;
use StepDispatcher\Transitions\PendingToDispatched;
use StepDispatcher\Transitions\PendingToFailed;
use StepDispatcher\Transitions\PendingToRunning;
use StepDispatcher\Transitions\PendingToSkipped;
use StepDispatcher\Transitions\RunningToCompleted;
use StepDispatcher\Transitions\RunningToFailed;
use StepDispatcher\Transitions\RunningToPending;
use StepDispatcher\Transitions\RunningToSkipped;
use StepDispatcher\Transitions\RunningToStopped;

abstract class StepStatus extends State
{
    /**
     * Get the string value of this state.
     */
    abstract public function value(): string;

    final public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(Pending::class, Dispatched::class, PendingToDispatched::class)
            ->allowTransition(Pending::class, Running::class, PendingToRunning::class)
            ->allowTransition(Pending::class, Cancelled::class, PendingToCancelled::class)
            ->allowTransition(Pending::class, Failed::class, PendingToFailed::class)
            ->allowTransition(Pending::class, Skipped::class, PendingToSkipped::class)

            ->allowTransition(Dispatched::class, Running::class, DispatchedToRunning::class)
            ->allowTransition(Dispatched::class, Cancelled::class, DispatchedToCancelled::class)
            ->allowTransition(Dispatched::class, Failed::class, DispatchedToFailed::class)

            ->allowTransition(Running::class, Completed::class, RunningToCompleted::class)
            ->allowTransition(Running::class, Stopped::class, RunningToStopped::class)
            ->allowTransition(Running::class, Failed::class, RunningToFailed::class)
            ->allowTransition(Running::class, Skipped::class, RunningToSkipped::class)
            ->allowTransition(Running::class, Pending::class, RunningToPending::class)

            ->allowTransition(NotRunnable::class, Pending::class, NotRunnableToPending::class)

            ->registerState([
                Pending::class,
                Dispatched::class,
                Running::class,
                Completed::class,
                Failed::class,
                Cancelled::class,
                NotRunnable::class,
                Skipped::class,
                Stopped::class]);
    }
}
