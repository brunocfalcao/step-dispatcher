<?php

declare(strict_types=1);

namespace StepDispatcher\Support;

/**
 * Process / request-scoped prefix stack the dispatcher consults to
 * resolve table names. Default prefix is the empty string `''`,
 * which preserves the package's original behaviour: tables resolve
 * to `steps`, `steps_dispatcher`, `steps_dispatcher_ticks`,
 * `steps_archive`. With prefix `'trading_'` they resolve to
 * `trading_steps`, `trading_steps_dispatcher`, etc.
 *
 * The prefix is the LITERAL trailing-underscore form. Concatenation
 * is plain string concat — `prefix . 'steps'`. The CLI accepts
 * user-friendly `--prefix=trading` and the binding code adds the
 * trailing underscore before pushing here.
 *
 * Three syntactic forms set the active prefix; resolution order
 * (innermost wins): explicit `Step::prefix(...)` > closure
 * `Steps::usingPrefix(...)` > ambient (boundary push) > default.
 *
 * Octane safety: the service provider binds this class as `scoped`,
 * giving each request / queued job its own instance. Under FPM the
 * scoped binding behaves identically to a singleton, so no harm.
 * Under Octane it prevents one request's prefix from leaking into a
 * sibling request sharing the same PHP process.
 *
 * Test isolation: `reset()` wipes the entire stack. Pest tests
 * MUST call `RuntimeContext::reset()` in afterEach so a test that
 * pushes and throws does not pollute the next test.
 */
final class RuntimeContext
{
    /**
     * @var list<string>
     */
    private array $stack = [];

    /**
     * Push a prefix onto the stack. The prefix is the LITERAL
     * trailing-underscore form (e.g. `'trading_'`). Use the empty
     * string `''` to push an explicit "default" frame — useful when
     * you want a closure block to forcibly write to the unprefixed
     * tables regardless of an outer ambient prefix.
     */
    public function push(string $prefix): void
    {
        $this->stack[] = $prefix;
    }

    /**
     * Pop the most recently pushed prefix. Safe to call when the
     * stack is empty — no-op in that case so a finally-block pop
     * never throws.
     */
    public function pop(): void
    {
        array_pop($this->stack);
    }

    /**
     * Read the current prefix without modifying the stack. Returns
     * the empty string when the stack is empty (default behaviour).
     */
    public function current(): string
    {
        return $this->stack[count($this->stack) - 1] ?? '';
    }

    /**
     * Wipe the entire stack. ONLY for test teardown — production
     * code should never need to clear the stack outside the
     * matching pop().
     */
    public function reset(): void
    {
        $this->stack = [];
    }

    /**
     * Stack depth — diagnostic helper for tests asserting that
     * push/pop pairings balance.
     */
    public function depth(): int
    {
        return count($this->stack);
    }
}
