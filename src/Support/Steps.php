<?php

declare(strict_types=1);

namespace StepDispatcher\Support;

use Closure;

/**
 * Public entry point for closure-scoped prefix changes. Push the
 * prefix on entry, run the closure, pop in finally regardless of
 * exception. Pairs cleanly so a throw inside the closure does NOT
 * leak the pushed prefix onto outer code.
 *
 * Accepts the user-friendly bare name (`'trading'`) and adds the
 * trailing underscore, OR the literal trailing-underscore form
 * (`'trading_'`) — both are normalised to the same on-stack value.
 * Empty string `''` is the explicit default.
 */
final class Steps
{
    /**
     * Run the closure with the given prefix as the active one.
     * Returns whatever the closure returns.
     *
     * @template T
     *
     * @param  Closure(): T  $closure
     * @return T
     */
    public static function usingPrefix(string $prefix, Closure $closure): mixed
    {
        $normalised = self::normalise($prefix);

        $context = app(RuntimeContext::class);
        $context->push($normalised);

        try {
            return $closure();
        } finally {
            $context->pop();
        }
    }

    /**
     * Read the active prefix. Convenience accessor for callers that
     * don't want to resolve the container binding themselves.
     */
    public static function currentPrefix(): string
    {
        return app(RuntimeContext::class)->current();
    }

    /**
     * Normalise a user-supplied prefix to the canonical
     * trailing-underscore form. Empty string stays empty (default).
     * Already-suffixed values are preserved as-is.
     */
    public static function normalise(string $prefix): string
    {
        if ($prefix === '') {
            return '';
        }

        return str_ends_with($prefix, '_') ? $prefix : $prefix.'_';
    }
}
