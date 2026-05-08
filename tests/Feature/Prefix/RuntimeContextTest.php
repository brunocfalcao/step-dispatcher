<?php

declare(strict_types=1);

use StepDispatcher\Support\RuntimeContext;
use StepDispatcher\Support\Steps;

/**
 * Foundation: the prefix stack itself.
 *
 * Every higher-level prefix behaviour (model getTable resolution,
 * worker handler restoration, closure-scoped overrides) leans on
 * RuntimeContext push/current/pop being exactly correct under all
 * stack shapes — empty, single-frame, nested, post-throw cleanup.
 * If the stack is wrong, every other test in the prefix suite is
 * meaningless because the wrong table would be queried regardless
 * of what the model code does.
 */
beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

it('returns the empty string as the default current prefix', function () {
    $context = app(RuntimeContext::class);

    expect($context->current())->toBe('',
        'Default prefix MUST be empty so existing host apps that '
        .'never push a prefix continue to resolve to `steps`, '
        .'`steps_dispatcher`, etc. — preserving backwards compatibility.'
    );

    expect($context->depth())->toBe(0);
});

it('returns the most recently pushed prefix as current', function () {
    $context = app(RuntimeContext::class);

    $context->push('trading_');
    expect($context->current())->toBe('trading_');
    expect($context->depth())->toBe(1);

    $context->push('calc_');
    expect($context->current())->toBe('calc_',
        'Innermost (most recently pushed) prefix wins. The stack '
        .'is the resolution model — push to override, pop to '
        .'restore the outer scope.'
    );
    expect($context->depth())->toBe(2);
});

it('restores the outer prefix when an inner frame is popped', function () {
    $context = app(RuntimeContext::class);

    $context->push('trading_');
    $context->push('calc_');
    $context->pop();

    expect($context->current())->toBe('trading_',
        'Pop must restore the outer frame. Otherwise a closure-scoped '
        .'override would silently corrupt the calling scope on exit.'
    );
    expect($context->depth())->toBe(1);
});

it('treats pop on an empty stack as a no-op so finally blocks never throw', function () {
    $context = app(RuntimeContext::class);

    // No exception expected.
    $context->pop();
    $context->pop();

    expect($context->current())->toBe('',
        'A double-pop or pop-on-empty must be tolerated so the '
        .'finally-block pop after a closure body throw is always safe.'
    );
});

it('reset clears the entire stack regardless of depth', function () {
    $context = app(RuntimeContext::class);
    $context->push('trading_');
    $context->push('calc_');
    $context->push('analytics_');

    $context->reset();

    expect($context->current())->toBe('');
    expect($context->depth())->toBe(0);
});

it('Steps::usingPrefix pushes and pops cleanly when the closure returns normally', function () {
    $context = app(RuntimeContext::class);
    expect($context->depth())->toBe(0);

    $captured = Steps::usingPrefix('trading_', function () use ($context): string {
        expect($context->current())->toBe('trading_');
        expect($context->depth())->toBe(1);

        return 'ok';
    });

    expect($captured)->toBe('ok');
    expect($context->depth())->toBe(0,
        'Closure body returned cleanly. The push/pop pair must '
        .'leave the stack at exactly the depth it was entered at.'
    );
});

it('Steps::usingPrefix pops even when the closure throws', function () {
    $context = app(RuntimeContext::class);
    expect($context->depth())->toBe(0);

    expect(function () {
        Steps::usingPrefix('trading_', function (): void {
            throw new RuntimeException('intentional');
        });
    })->toThrow(RuntimeException::class, 'intentional');

    expect($context->depth())->toBe(0,
        'A throw inside the closure body must NOT leak the pushed '
        .'prefix into the surrounding scope. The finally-block pop '
        .'is the only thing standing between us and silent table '
        .'corruption from a stray exception.'
    );
});

it('Steps::usingPrefix normalises bare names into the trailing-underscore form', function () {
    $context = app(RuntimeContext::class);

    Steps::usingPrefix('trading', function () use ($context): void {
        expect($context->current())->toBe('trading_',
            'CLI-friendly bare names get the trailing underscore added '
            .'automatically so callers do not have to remember the '
            .'concatenation convention.'
        );
    });

    Steps::usingPrefix('calc_', function () use ($context): void {
        expect($context->current())->toBe('calc_',
            'Already-suffixed values pass through unchanged. The '
            .'normalisation is idempotent.'
        );
    });

    Steps::usingPrefix('', function () use ($context): void {
        expect($context->current())->toBe('',
            'Empty string stays empty — explicit default scope.'
        );
    });
});

it('nested usingPrefix calls compose correctly', function () {
    $context = app(RuntimeContext::class);

    Steps::usingPrefix('trading_', function () use ($context): void {
        expect($context->current())->toBe('trading_');

        Steps::usingPrefix('calc_', function () use ($context): void {
            expect($context->current())->toBe('calc_',
                'Nested override wins while the inner closure runs.'
            );
        });

        expect($context->current())->toBe('trading_',
            'Outer prefix restored once the inner closure returns. '
            .'A trading-tier handler that briefly fans out a calc '
            .'child must end the fan-out back inside the trading scope.'
        );
    });

    expect($context->current())->toBe('');
});
