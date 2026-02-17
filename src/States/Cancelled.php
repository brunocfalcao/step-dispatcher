<?php

declare(strict_types=1);

namespace StepDispatcher\States;

use StepDispatcher\Abstracts\StepStatus;

final class Cancelled extends StepStatus
{
    public const VALUE = 'cancelled';

    public function value(): string
    {
        return self::VALUE;
    }
}
