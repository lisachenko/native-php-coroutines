<?php

/**
 * Native PHP coroutines
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Tasks and bounding helpers for the worker-lifecycle suite.
 *
 * Everything here exists to keep the fork/signal tests **bounded and self-cleaning**. A hanging test
 * in this area is far worse than a failing one, and a test that leaves a process behind poisons
 * every test after it, so each helper either has a deadline or is a way to prove nothing survived.
 *
 * The tasks are ordinary classes rather than closures on purpose: {@see Task} is an object contract
 * precisely because a closure carries bindings that cannot cross a fork boundary.
 */
declare(strict_types=1);

namespace Lisachenko\NativePhpCoroutines\Tests\Support;

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Parallel\Task;
use Lisachenko\NativePhpCoroutines\RuntimeInterface;
use Lisachenko\NativePhpCoroutines\Timer;

/** Returns whatever it was built with — the simplest possible round trip. */
final class ConstantTask implements Task
{
    public function __construct(private readonly mixed $value) {}

    public function run(RuntimeInterface $runtime): mixed
    {
        return $this->value;
    }
}

/** Adds two numbers in the worker, so a result proves work actually happened over there. */
final class SumTask implements Task
{
    public function __construct(private readonly int $left, private readonly int $right) {}

    public function run(RuntimeInterface $runtime): mixed
    {
        return $this->left + $this->right;
    }
}

/** Reports the pid it ran in — how a test proves which worker took the work. */
final class PidTask implements Task
{
    public function run(RuntimeInterface $runtime): mixed
    {
        return posix_getpid();
    }
}

/** Parks on the worker's own scheduler, leaving a window to kill the worker mid-task. */
final class SleepingTask implements Task
{
    public function __construct(private readonly float $seconds, private readonly int $value = 0) {}

    public function run(RuntimeInterface $runtime): mixed
    {
        Coroutine::sleep($this->seconds);

        return $this->value;
    }
}

/** Ends in an uncaught throwable, which the worker reports as a PANIC record. */
final class PanickingTask implements Task
{
    public function run(RuntimeInterface $runtime): mixed
    {
        throw new \RuntimeException('the task exploded');
    }
}

/**
 * What "no children" looks like from here.
 *
 * `waitpid(-1, WNOHANG)` answers all three cases in one call: -1 means this process has no children
 * at all, 0 means it has some that are still running, and a pid means one had exited and was sitting
 * there as a zombie. Only the first is a clean exit from a test.
 */
function parallelChildrenLeft(): string
{
    $status = 0;
    $pid    = pcntl_waitpid(-1, $status, WNOHANG);

    return match (true) {
        $pid === -1 => 'none',
        $pid === 0  => 'still running',
        default     => 'a zombie',
    };
}

/**
 * Arm a hard deadline on the active scheduler.
 *
 * The callback runs on the scheduler's own stack, so the throw unwinds out of `runUntil()` and the
 * test fails with a message naming what it was waiting for — instead of blocking the suite forever.
 */
function parallelDeadline(float $seconds, string $what): void
{
    Timer::after($seconds, static function () use ($what): void {
        throw new \RuntimeException(sprintf('deadline: %s did not happen within %.1fs', $what, $seconds));
    });
}

/**
 * Wait for a condition outside any scheduler, with a deadline.
 *
 * @param \Closure(): bool $condition
 */
function parallelWaitFor(\Closure $condition, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;

    while (!$condition()) {
        if (microtime(true) >= $deadline) {
            return false;
        }

        usleep(2000);
    }

    return true;
}

/**
 * Block until a raw stream reaches EOF, or the deadline passes.
 *
 * Used as a liveness probe: a descriptor held open only by a process reaches EOF exactly when that
 * process is gone, which is a cleaner signal than polling a pid — a zombie still answers `kill 0`.
 *
 * @param resource $stream
 */
function parallelAwaitEof($stream, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;

    while (true) {
        $left = $deadline - microtime(true);

        if ($left <= 0.0) {
            return false;
        }

        $read   = [$stream];
        $write  = [];
        $except = [];

        $ready = @stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1.0) * 1_000_000));

        if ($ready === false) {
            continue;
        }

        if ($ready === 0) {
            return false;
        }

        $chunk = @fread($stream, 4096);

        if (($chunk === false || $chunk === '') && feof($stream)) {
            return true;
        }
    }
}
