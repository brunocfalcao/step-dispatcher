<?php

declare(strict_types=1);

namespace StepDispatcher\Support;

use Closure;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * BaseCommand
 *
 * Base command class with verbose output control.
 * All output methods (info, line, warn, error, etc.) respect the --output flag.
 * Without --output, commands produce ZERO output (to save disk space in production).
 */
abstract class BaseCommand extends Command
{
    /**
     * Get the console command options.
     *
     * @return array<int, InputOption>
     */
    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            new InputOption('output', null, InputOption::VALUE_NONE, 'Display command output (silent by default)'),
            new InputOption(
                'prefix',
                null,
                InputOption::VALUE_OPTIONAL,
                'Runtime table prefix (e.g. trading or trading_). Empty / absent = default tables (steps, steps_dispatcher, ...). Pushed onto RuntimeContext for the duration of the command.',
                ''
            ),
        ]);
    }

    /**
     * Push the --prefix= value onto the RuntimeContext for the
     * duration of the command. Returns a callable to invoke in
     * `finally` so a throw inside `handle()` still pops the stack.
     *
     * Subclasses that override `execute()` should wrap their work
     * with the returned callable.
     */
    protected function pushRuntimePrefix(): Closure
    {
        $context = app(RuntimeContext::class);
        $rawPrefix = $this->hasOption('prefix') ? (string) $this->option('prefix') : '';
        $normalised = Steps::normalise($rawPrefix);
        $context->push($normalised);

        return static function () use ($context): void {
            $context->pop();
        };
    }

    /**
     * Symfony / Laravel calls execute() to dispatch handle(). Wrap
     * it with the prefix push/pop so the entire command body sees
     * the prefix without subclasses having to remember.
     */
    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $pop = $this->pushRuntimePrefix();

        try {
            return parent::execute($input, $output);
        } finally {
            $pop();
        }
    }

    /**
     * Check if output should be displayed.
     */
    protected function shouldOutput(): bool
    {
        try {
            return $this->hasOption('output') && $this->option('output');
        } catch (Throwable $e) {
            // If option doesn't exist or can't be accessed, default to silent
            return false;
        }
    }

    /**
     * Output info message (only if --output).
     */
    protected function verboseInfo(string $message): void
    {
        if ($this->shouldOutput()) {
            $this->info($message);
        }
    }

    /**
     * Output line message (only if --output).
     */
    protected function verboseLine(string $message, ?string $style = null): void
    {
        if ($this->shouldOutput()) {
            $this->line($message, $style);
        }
    }

    /**
     * Output warning message (only if --output).
     */
    protected function verboseWarn(string $message): void
    {
        if ($this->shouldOutput()) {
            $this->warn($message);
        }
    }

    /**
     * Output error message (only if --output).
     */
    protected function verboseError(string $message): void
    {
        if ($this->shouldOutput()) {
            $this->error($message);
        }
    }

    /**
     * Output comment message (only if --output).
     */
    protected function verboseComment(string $message): void
    {
        if ($this->shouldOutput()) {
            $this->comment($message);
        }
    }

    /**
     * Output new line (only if --output).
     */
    protected function verboseNewLine(int $count = 1): void
    {
        if ($this->shouldOutput()) {
            $this->newLine($count);
        }
    }

    /**
     * Output table (only if --output).
     *
     * @param  array<string>  $headers
     * @param  array<array<string>>  $rows
     */
    protected function verboseTable(array $headers, array $rows): void
    {
        if ($this->shouldOutput()) {
            $this->table($headers, $rows);
        }
    }
}
