<?php

declare(strict_types=1);

namespace StepDispatcher\Concerns\Step;

use Illuminate\Support\Facades\File;

/**
 * Step-scoped file logging — a diagnostic-only channel for understanding
 * what happened to an individual step without having to parse application
 * logs or the steps table row by row.
 *
 * Layout (when enabled):
 *
 *   storage/logs/
 *   ├── dispatcher.log                   # global tick-level events (not step-scoped)
 *   └── steps/
 *       └── {step_id}/
 *           ├── states.log               # every state transition
 *           ├── throttled.log            # reschedule events from the throttler path
 *           ├── retries.log              # retry cycles
 *           └── exceptions.log           # caught exceptions + handler outcome
 *
 * All writes are gated by a single config flag (mapped to an env var), so the
 * mechanism stays cheap when disabled. When the flag is off `log()` returns
 * immediately before touching the filesystem.
 *
 * Intended use: flip on temporarily to diagnose a specific backlog or wedge,
 * collect a few minutes of data, flip off. Folders are deleted when the
 * underlying step row is deleted (StepObserver::deleted), so archived or
 * purged steps don't leak log directories.
 */
trait HasStepLogging
{
    /**
     * Append a line to a per-step channel log.
     *
     * Channels map 1:1 to filenames inside the step's folder, e.g.
     * channel 'states' writes to `logs/steps/{stepId}/states.log`.
     *
     * @param  int|string|null  $stepId  Step ID (required; use logGlobal() for dispatcher-wide events)
     * @param  string  $channel  Channel name — becomes the filename (states/throttled/retries/exceptions/...)
     * @param  string  $message  Line to append (no trailing newline needed)
     */
    public static function log(int|string|null $stepId, string $channel, string $message): void
    {
        if (! self::loggingEnabled()) {
            return;
        }

        if ($stepId === null) {
            // No step context — fall through to the global dispatcher log so
            // nothing is silently dropped when called in an ambiguous spot.
            self::logGlobal($channel, $message);

            return;
        }

        $stepDir = self::stepLogDir($stepId);

        if (! is_dir($stepDir)) {
            // Directory can be missing if the step was created before the
            // logging flag was flipped on. Create it lazily — cheap.
            @mkdir($stepDir, 0o755, true);
        }

        $file = $stepDir.DIRECTORY_SEPARATOR.self::sanitizeChannel($channel).'.log';
        @file_put_contents($file, self::formatLine($message), FILE_APPEND | LOCK_EX);
    }

    /**
     * Append a line to a global (non-step-scoped) log file under logs/.
     *
     * Used for tick-level dispatcher events that aren't attributable to a
     * single step — e.g. "tick started for group alpha, 17 pending".
     */
    public static function logGlobal(string $channel, string $message): void
    {
        if (! self::loggingEnabled()) {
            return;
        }

        $dir = self::basePath();

        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        $file = $dir.DIRECTORY_SEPARATOR.self::sanitizeChannel($channel).'.log';
        @file_put_contents($file, self::formatLine($message), FILE_APPEND | LOCK_EX);
    }

    /**
     * Read the contents of a per-step channel log. Returns null if disabled
     * or the file doesn't exist.
     */
    public function getLogContents(string $channel = 'states'): ?string
    {
        $file = self::stepLogDir($this->getKey()).DIRECTORY_SEPARATOR.self::sanitizeChannel($channel).'.log';

        if (! file_exists($file)) {
            return null;
        }

        return file_get_contents($file);
    }

    /**
     * Delete this step's entire log folder. Called from StepObserver::deleted
     * so archived or purged steps don't leave orphaned log directories.
     */
    public function clearLogs(): void
    {
        $dir = self::stepLogDir($this->getKey());

        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Master kill-switch. One read per call — cheap (array lookup in the
     * config cache) so we don't worry about caching it at the trait level.
     */
    protected static function loggingEnabled(): bool
    {
        return (bool) config('step-dispatcher.logging.enabled', false);
    }

    /**
     * Resolve the base log directory — defaults to `storage/logs` so step
     * folders live under `storage/logs/steps/{id}` and global files land at
     * `storage/logs/{channel}.log`. Configurable if the host app wants to
     * isolate dispatcher logs elsewhere.
     */
    protected static function basePath(): string
    {
        return (string) config('step-dispatcher.logging.path', storage_path('logs'));
    }

    /**
     * Resolve the folder that holds all channel logs for a given step.
     */
    protected static function stepLogDir(int|string $stepId): string
    {
        return self::basePath().DIRECTORY_SEPARATOR.'steps'.DIRECTORY_SEPARATOR.$stepId;
    }

    /**
     * Timestamped single-line format shared across all channels.
     *
     * Format: "HH:MM:SS.µs | <message>\n"
     * — microsecond precision because state transitions are sub-ms,
     * — pipe separator plays well with grep/awk,
     * — no leading date because files rotate per-step (short-lived).
     */
    protected static function formatLine(string $message): string
    {
        return now()->format('H:i:s.u').' | '.rtrim($message, "\r\n").PHP_EOL;
    }

    /**
     * Strip anything from the channel name that isn't filename-safe.
     * Defensive — channel names are always hardcoded at call sites today,
     * but this keeps the trait safe against a future caller passing user
     * input by mistake.
     */
    protected static function sanitizeChannel(string $channel): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $channel);

        return $clean !== '' ? $clean : 'misc';
    }
}
