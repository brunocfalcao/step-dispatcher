<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Collision;

use RuntimeException;
use Throwable;

/**
 * Starts real PHP worker processes that all wait for one shared instant, then
 * run their contended call against the collision database at the same time.
 *
 * Each worker has a hard deadline. A worker still running past it is killed
 * and reported as timed out, so a deadlock fails the test instead of hanging
 * it. Every process handle is closed in terminate(), which the caller runs in
 * a finally block.
 */
final class CollisionWorkerBatch
{
    public const TIMEOUT_SECONDS = 30;

    public const START_LEAD_MICROSECONDS = 300_000;

    private const SCRIPT = __DIR__.'/worker.php';

    /** @var list<array{label: string, handle: mixed, stdout: string, stderr: string, launched_at: float, exit_code: ?int, timed_out: bool}> */
    private array $workers = [];

    private function __construct(private readonly string $directory) {}

    /**
     * @param  list<array{scenario: string, arguments: array<string, mixed>}>  $workers
     */
    public static function launch(array $workers, string $directory): self
    {
        $batch = new self($directory);
        $startAt = (int) round(microtime(true) * 1_000_000) + self::START_LEAD_MICROSECONDS;

        try {
            foreach ($workers as $index => $worker) {
                $batch->spawn(
                    $index,
                    $worker['scenario'],
                    $worker['arguments'] + ['start_at_us' => $startAt],
                );
            }
        } catch (Throwable $e) {
            $batch->terminate();

            throw $e;
        }

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function spawn(int $index, string $scenario, array $arguments): void
    {
        $arguments['work_directory'] = $this->directory;

        $stdout = $this->directory.'/worker-'.$index.'-'.uniqid('', true).'.out';
        $stderr = $this->directory.'/worker-'.$index.'-'.uniqid('', true).'.err';

        $handle = proc_open(
            [PHP_BINARY, self::SCRIPT, $scenario, json_encode($arguments, JSON_THROW_ON_ERROR)],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $stdout, 'w'],
                2 => ['file', $stderr, 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
        );

        if (! is_resource($handle)) {
            throw new RuntimeException("Could not start collision worker {$index} for scenario {$scenario}.");
        }

        $this->workers[] = [
            'label' => $scenario.'#'.$index,
            'handle' => $handle,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'launched_at' => microtime(true),
            'exit_code' => null,
            'timed_out' => false,
        ];
    }

    /**
     * Waits for every worker to exit, killing any that pass the deadline, and
     * returns one report per worker in launch order.
     *
     * @return list<array{label: string, exit_code: ?int, timed_out: bool, payload: ?array<string, mixed>, stderr: string}>
     */
    public function wait(): array
    {
        while (true) {
            $pending = false;

            foreach ($this->workers as $index => $worker) {
                if ($worker['exit_code'] !== null || ! is_resource($worker['handle'])) {
                    continue;
                }

                $status = proc_get_status($worker['handle']);

                if ($status['running']) {
                    $pending = true;

                    if (microtime(true) - $worker['launched_at'] > self::TIMEOUT_SECONDS) {
                        $this->workers[$index]['timed_out'] = true;
                        proc_terminate($worker['handle'], 9);
                    }

                    continue;
                }

                $this->workers[$index]['exit_code'] = $status['exitcode'];
            }

            if (! $pending) {
                break;
            }

            usleep(2000);
        }

        $reports = [];

        foreach ($this->workers as $index => $worker) {
            $reports[] = [
                'label' => $worker['label'],
                'exit_code' => $worker['exit_code'],
                'timed_out' => $worker['timed_out'],
                'payload' => $this->decodePayload($worker['stdout']),
                'stderr' => $this->readTail($worker['stderr']),
            ];

            if (is_resource($worker['handle'])) {
                proc_close($worker['handle']);
            }
        }

        return $reports;
    }

    /**
     * Kills and reaps anything still running. Safe to call after wait().
     */
    public function terminate(): void
    {
        foreach ($this->workers as $index => $worker) {
            if (! is_resource($worker['handle'])) {
                continue;
            }

            $status = proc_get_status($worker['handle']);

            if ($status['running']) {
                proc_terminate($worker['handle'], 9);
            }

            proc_close($worker['handle']);
            $this->workers[$index]['exit_code'] ??= -1;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $path): ?array
    {
        $contents = is_file($path) ? trim((string) file_get_contents($path)) : '';

        if ($contents === '') {
            return null;
        }

        $lines = array_values(array_filter(explode("\n", $contents), static fn (string $line): bool => trim($line) !== ''));
        $decoded = json_decode((string) end($lines), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function readTail(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        return substr((string) file_get_contents($path), -4000);
    }
}
