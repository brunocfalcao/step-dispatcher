<?php

declare(strict_types=1);

namespace StepDispatcher\States;

use StepDispatcher\Abstracts\StepStatus;

final class Stopped extends StepStatus
{
    public const VALUE = 'stopped';

    public function value(): string
    {
        return self::VALUE;
    }
}
