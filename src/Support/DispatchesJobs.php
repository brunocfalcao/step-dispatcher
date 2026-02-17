<?php

declare(strict_types=1);

namespace StepDispatcher\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Throwable;

trait DispatchesJobs
{
    public function dispatchSingleStep(Step $step): void
    {
        if (empty($step->class)) {
            $step->state->transitionTo(Failed::class);
            Log::error("[DispatchesJobs] Step {$step->id} has no class defined.");

            return;
        }

        try {
            $job = self::instantiateJobWithArguments($step->class, $step->arguments);
            $job->step = $step;

            if ($step->queue === 'sync') {
                $job->handle();
            } else {
                Queue::pushOn($step->queue, $job);
            }
        } catch (Throwable $e) {
            $step->update(['error_message' => ExceptionParser::with($e)->friendlyMessage()]);
            $step->update(['error_stack_trace' => ExceptionParser::with($e)->stackTrace()]);

            // Only transition to Failed if not already in a terminal state
            $step->refresh();

            $terminalStates = Step::terminalStepStates();
            $isTerminal = collect($terminalStates)->contains(static function ($state) use ($step) {
                return $step->state->equals($state);
            });

            if (! $isTerminal) {
                $step->state->transitionTo(Failed::class);
            }

            Log::error('[DispatchSingleStep] EXCEPTION: '.$e->getMessage(), [
                'step_id' => $step->id,
                'class' => $step->class,
            ]);
        }
    }

    protected static function instantiateJobWithArguments(string $class, ?array $arguments)
    {
        try {
            $arguments ??= [];
            $reflectionClass = new ReflectionClass($class);
            $constructor = $reflectionClass->getConstructor();

            if (is_null($constructor)) {
                return new $class;
            }

            $parameters = $constructor->getParameters();
            $resolvedArguments = [];
            $missingArguments = [];

            foreach ($parameters as $parameter) {
                $name = $parameter->getName();

                if (array_key_exists(key: $name, array: $arguments)) {
                    $resolvedArguments[] = $arguments[$name];
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $resolvedArguments[] = $parameter->getDefaultValue();
                } else {
                    $missingArguments[] = $name;
                }
            }

            if (! empty($missingArguments)) {
                throw new InvalidArgumentException(
                    '[DispatchesJobs] Missing required arguments: '.implode(separator: ', ', array: $missingArguments)." for class {$class}"
                );
            }

            return $reflectionClass->newInstanceArgs($resolvedArguments);
        } catch (ReflectionException $e) {
            throw new RuntimeException("[DispatchesJobs] Failed to instantiate job class {$class}: ".$e->getMessage(), 0, $e);
        }
    }
}
