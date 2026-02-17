<?php

declare(strict_types=1);

namespace StepDispatcher\States;

use StepDispatcher\Abstracts\StepStatus;

final class Failed extends StepStatus
{
    public const VALUE = 'failed';

    public function value(): string
    {
        return self::VALUE;
    }
}
