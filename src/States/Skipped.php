<?php

declare(strict_types=1);

namespace StepDispatcher\States;

use StepDispatcher\Abstracts\StepStatus;

final class Skipped extends StepStatus
{
    public const VALUE = 'skipped';

    public function value(): string
    {
        return self::VALUE;
    }
}
