<?php

declare(strict_types=1);

namespace StepDispatcher\States;

use StepDispatcher\Abstracts\StepStatus;

final class Dispatched extends StepStatus
{
    public const VALUE = 'dispatched';

    public function value(): string
    {
        return self::VALUE;
    }
}
