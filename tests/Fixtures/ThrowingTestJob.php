<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use Throwable;
use StepDispatcher\Abstracts\BaseStepJob;

/**
 * Test fixture for the HandlesStepExceptions decision tree. compute()
 * throws whatever exception the test pins on the static property, and the
 * three classification hooks (retryException / ignoreException /
 * resolveException) read static toggles so a single fixture can drive
 * every branch of handleException(): retry, ignore, resolve, fail,
 * permanent-DB, and the MaxRetriesReached shortcut.
 */
final class ThrowingTestJob extends BaseStepJob
{
    public int $retries = 3;

    public static ?Throwable $throw = null;

    public static bool $retry = false;

    public static bool $ignore = false;

    /** State class the resolveException hook transitions the step to (null = no-op). */
    public static ?string $resolveTo = null;

    protected function compute(): mixed
    {
        if (static::$throw !== null) {
            throw static::$throw;
        }

        return ['ran' => true];
    }

    public function retryException(Throwable $e): bool
    {
        return static::$retry;
    }

    public function ignoreException(Throwable $e): bool
    {
        return static::$ignore;
    }

    public function resolveException(Throwable $e): void
    {
        if (static::$resolveTo !== null) {
            $this->step->state->transitionTo(static::$resolveTo);
            $this->stepStatusUpdated = true;
        }
    }

    public static function reset(): void
    {
        static::$throw = null;
        static::$retry = false;
        static::$ignore = false;
        static::$resolveTo = null;
    }
}
