<?php

declare(strict_types=1);

namespace StepDispatcher\States;

use StepDispatcher\Abstracts\StepStatus;

final class Completed extends StepStatus
{
    public const VALUE = 'completed';

    public function value(): string
    {
        return self::VALUE;
    }
}
