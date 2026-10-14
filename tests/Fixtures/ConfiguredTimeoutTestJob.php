<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use StepDispatcher\Abstracts\BaseStepJob;

final class ConfiguredTimeoutTestJob extends BaseStepJob
{
    public static ?int $runtimeTimeout = null;

    public int $timeout = 1800;

    public int $retries = 1;

    public static function timeoutSeconds(): ?int
    {
        return self::$runtimeTimeout;
    }

    protected function compute(): mixed
    {
        return ['ran' => true];
    }
}
