<?php

declare(strict_types=1);

namespace StepDispatcher\Exceptions;

use RuntimeException;

/**
 * Thrown by a registered queue resolver when it cannot pick a physical
 * queue for a step — typically because every worker eligible for the
 * step's logical category is unreachable / banned / not whitelisted on
 * the target external system.
 *
 * The dispatcher's `DispatchesJobs::dispatchSingleStep()` try/catch
 * surfaces this exception by transitioning the step to Failed and
 * recording the exception message on the step row. Any side effects
 * the resolver wants the user to observe (notifications, account
 * deactivation, audit logs) MUST be fired by the resolver itself
 * before throwing — the framework does not interpret the exception
 * beyond the standard "this step cannot be dispatched" semantic.
 */
final class NoCleanWorkerException extends RuntimeException {}
