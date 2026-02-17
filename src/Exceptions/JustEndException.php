<?php

declare(strict_types=1);

namespace StepDispatcher\Exceptions;

use Exception;

/**
 * Type of exception used on the BaseStepJob, that will not call any
 * additional methods, but just end the BaseStepJob catch() block.
 */
final class JustEndException extends Exception {}
