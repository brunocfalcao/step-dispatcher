<?php

declare(strict_types=1);

namespace StepDispatcher\States;

use StepDispatcher\Abstracts\StepStatus;

final class Pending extends StepStatus
{
    public const VALUE = 'pending';

    public function value(): string
    {
        return self::VALUE;
    }
}
