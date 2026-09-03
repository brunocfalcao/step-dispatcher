<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use StepDispatcher\Abstracts\BaseStepJob;
use Throwable;

/**
 * Test fixture used by `BaseStepJobFailurePathTest`. Records whether the
 * job's own resolveException() hook ran, and with which step error message,
 * so the test can pin: does a queue-level kill (timeout, SIGKILL) still let
 * a job release the domain record it left in an in-progress state?
 */
final class ResolvingOnFailureTestJob extends BaseStepJob
{
    public static bool $resolved = false;

    public static ?string $resolvedErrorMessage = null;

    public int $retries = 1;

    public static function reset(): void
    {
        self::$resolved = false;
        self::$resolvedErrorMessage = null;
    }

    protected function compute(): mixed
    {
        return ['ok' => true];
    }

    protected function resolveException(Throwable $e): void
    {
        self::$resolved = true;
        self::$resolvedErrorMessage = $this->step->error_message;
    }
}
