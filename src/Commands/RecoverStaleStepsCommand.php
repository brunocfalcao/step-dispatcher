<?php

declare(strict_types=1);

namespace StepDispatcher\Commands;

use Illuminate\Support\Facades\Log;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\BaseCommand;

final class RecoverStaleStepsCommand extends BaseCommand
{
    protected $signature = 'steps:recover-stale {--output : Display command output (silent by default)}';

    protected $description = 'Recover steps orphaned in Running state after worker process death';

    /**
     * Buffer added to the job timeout before considering a step stale (seconds).
     */
    private const BUFFER_SECONDS = 60;

    /**
     * Fallback timeout when the job class cannot be resolved (seconds).
     */
    private const DEFAULT_TIMEOUT = 300;

    public function handle(): int
    {
        $staleSteps = Step::where('state', Running::class)
            ->whereNotNull('started_at')
            ->get();

        if ($staleSteps->isEmpty()) {
            return self::SUCCESS;
        }

        $recovered = 0;

        foreach ($staleSteps as $step) {
            $timeout = $this->resolveJobTimeout($step);
            $staleThreshold = $timeout + self::BUFFER_SECONDS;
            $runningSeconds = (int) $step->started_at->diffInSeconds(now());

            if ($runningSeconds < $staleThreshold) {
                continue;
            }

            $maxRetries = $this->resolveJobMaxRetries($step);

            if ($step->retries >= $maxRetries) {
                $step->update([
                    'error_message' => "Recovered by stale detector: Running for {$runningSeconds}s (threshold: {$staleThreshold}s), retries exhausted ({$step->retries}/{$maxRetries})",
                ]);
                $step->state->transitionTo(Failed::class);

                Log::warning('Stale step failed (retries exhausted)', [
                    'step_id' => $step->id,
                    'class' => $step->class,
                    'label' => $step->label,
                    'running_seconds' => $runningSeconds,
                    'retries' => $step->retries,
                ]);
            } else {
                $step->update([
                    'error_message' => "Recovered by stale detector: Running for {$runningSeconds}s (threshold: {$staleThreshold}s), retrying ({$step->retries}/{$maxRetries})",
                ]);
                $step->state->transitionTo(Pending::class);

                Log::warning('Stale step recovered to Pending', [
                    'step_id' => $step->id,
                    'class' => $step->class,
                    'label' => $step->label,
                    'running_seconds' => $runningSeconds,
                    'retries' => $step->retries + 1,
                ]);
            }

            $recovered++;
            $this->verboseLine("[Step {$step->id}] {$step->label}: recovered ({$step->class})");
        }

        if ($recovered > 0) {
            $this->verboseInfo("Recovered {$recovered} stale step(s).");
        }

        return self::SUCCESS;
    }

    private function resolveJobTimeout(Step $step): int
    {
        if (! $step->class || ! class_exists($step->class)) {
            return self::DEFAULT_TIMEOUT;
        }

        try {
            $reflection = new \ReflectionClass($step->class);
            $property = $reflection->getProperty('timeout');
            $value = (int) $property->getDefaultValue();

            // $timeout = 0 is a Laravel convention meaning "rely on the queue worker's
            // own timeout". For stale detection we need a positive number, so fall back
            // to DEFAULT_TIMEOUT. Without this, every such job is considered stale after
            // 60s (0 + BUFFER), which kills legitimate long-running work.
            return $value > 0 ? $value : self::DEFAULT_TIMEOUT;
        } catch (\ReflectionException) {
            return self::DEFAULT_TIMEOUT;
        }
    }

    private function resolveJobMaxRetries(Step $step): int
    {
        if (! $step->class || ! class_exists($step->class)) {
            return 2;
        }

        try {
            $reflection = new \ReflectionClass($step->class);
            $property = $reflection->getProperty('retries');

            return (int) $property->getDefaultValue();
        } catch (\ReflectionException) {
            return 2;
        }
    }
}
