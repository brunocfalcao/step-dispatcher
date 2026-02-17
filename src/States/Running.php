<?php

declare(strict_types=1);

namespace StepDispatcher\States;

use StepDispatcher\Abstracts\StepStatus;

final class Running extends StepStatus
{
    public const VALUE = 'running';

    public function value(): string
    {
        return self::VALUE;
    }
}
