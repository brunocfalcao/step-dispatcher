<?php

declare(strict_types=1);

namespace StepDispatcher\Concerns\Step;

/**
 * Provides file-based logging for Step models.
 *
 * Logs are stored at: logs/steps/{step_id}/step.log
 * Global dispatcher logs: logs/dispatcher.log
 *
 * Controlled by config: step-dispatcher.logging.enabled
 */
trait HasStepLogging
{
    /**
     * Log a message for a step by ID.
     *
     * @param  int|string|null  $stepId  The step ID (null for global dispatcher logs)
     * @param  string  $message  The message to log
     */
    public static function log(int|string|null $stepId, string $message): void
    {
        if (! config('step-dispatcher.logging.enabled', false)) {
            return;
        }

        // Global dispatcher logs (stepId = null)
        if ($stepId === null) {
            $logFile = storage_path('logs/dispatcher.log');
        } else {
            $logsPath = storage_path("logs/steps/{$stepId}");
            $logFile = "{$logsPath}/step.log";

            if (! is_dir($logsPath)) {
                mkdir($logsPath, 0o755, true);
            }
        }

        $timestamp = now()->format('Y-m-d H:i:s.u');
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;

        file_put_contents($logFile, $logMessage, flags: FILE_APPEND | LOCK_EX);
    }

    /**
     * Get the log contents for this step.
     *
     * @return string|null The log contents or null if file doesn't exist
     */
    public function getLogContents(): ?string
    {
        $logFile = storage_path("logs/steps/{$this->id}/step.log");

        if (! file_exists($logFile)) {
            return null;
        }

        return file_get_contents($logFile);
    }

    /**
     * Clear logs for this step.
     */
    public function clearLogs(): void
    {
        $stepDir = storage_path("logs/steps/{$this->id}");

        if (is_dir($stepDir)) {
            \Illuminate\Support\Facades\File::deleteDirectory($stepDir);
        }
    }
}
