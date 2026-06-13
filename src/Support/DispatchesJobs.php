<?php

declare(strict_types=1);

namespace StepDispatcher\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
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
            // Consumer-app queue routing override. When the host
            // application has registered a queue resolver via
            // `StepDispatcher::setQueueResolver()`, the resolver
            // decides the physical queue the step should land on
            // (e.g. picking a clean worker by IP affinity in Kraite).
            // Sync steps skip the resolver entirely — synchronous
            // execution doesn't go through a Redis queue, so routing
            // has no meaning there.
            //
            // A `null` return means "no opinion" (leave step.queue
            // alone). Any non-null string replaces step.queue and is
            // persisted to the row so retries / debugging / horizon
            // dashboards see the actual physical queue. A thrown
            // NoCleanWorkerException propagates to the outer catch,
            // which transitions the step to Failed and records the
            // exception message on the row.
            if ($step->queue !== 'sync') {
                $resolver = StepDispatcher::getQueueResolver();

                if ($resolver !== null) {
                    $resolved = $resolver($step);

                    if (is_string($resolved) && $resolved !== $step->queue) {
                        $step->queue = $resolved;
                        $step->save();
                    }
                }
            }

            $job = self::instantiateJobWithArguments($step->class, $step->arguments);
            $job->step = $step;

            // Stamp the ambient prefix onto the job payload so the
            // worker (which boots in a fresh process / scoped
            // container with an empty prefix stack) can restore the
            // right ambient before any DB read against the Step
            // model. Default `''` keeps unprefixed behaviour.
            $job->stepPrefix = app(RuntimeContext::class)->current();

            if ($step->queue === 'sync') {
                $job->handle();
            } else {
                Queue::pushOn($step->queue, $job);
            }
        } catch (Throwable $e) {
            // Re-read state first: the dispatch tick put this step in
            // Dispatched, but recover-stale (or an operator) may have moved
            // it concurrently — e.g. requeued it to Pending. Only fail the
            // step when it is still in OUR Dispatched state; transitioning
            // a requeued step to Failed would erase that recovery (Pending
            // → Failed is a registered transition, so it would succeed).
            $step->refresh();

            if ($step->state instanceof Dispatched) {
                $parser = ExceptionParser::with($e);

                $step->update([
                    'error_message' => $parser->friendlyMessage(),
                    'error_stack_trace' => $parser->stackTrace(),
                ]);

                $step->state->transitionTo(Failed::class);
            }

            Log::error('[DispatchSingleStep] EXCEPTION: '.$e->getMessage(), [
                'step_id' => $step->id,
                'class' => $step->class,
                'state' => get_class($step->state),
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
