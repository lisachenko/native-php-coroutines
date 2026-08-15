<?php

/**
 * Native PHP coroutines
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */
declare(strict_types=1);

namespace Lisachenko\NativePhpCoroutines\Parallel;

use Lisachenko\NativePhpCoroutines\Exception\WorkerCrashedException;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\ControlRecord;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Opcode;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Tag;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;

/**
 * A worker backed by a forked process, as the parent sees it.
 *
 * # Why the fork happens where it happens
 *
 * {@see self::fork()} is called from {@see WorkerSupervisor::start()}, and the order around it is a
 * correctness requirement rather than a preference:
 *
 * 1. **the arena and the shared roots are created first**, so children inherit them at the same
 *    addresses — that inheritance is the entire basis of zero-copy sharing;
 * 2. **then the workers fork**;
 * 3. **fibers are created only afterwards**. A fiber owns a C stack. Forking with live fibers hands
 *    every child a duplicate of those stacks in a state no child can safely resume, and the failure
 *    is a crash inside the engine rather than an exception.
 *
 * That is why the child builds its own {@see \Lisachenko\NativePhpCoroutines\Runtime} — and with it
 * its scheduler and every fiber it will ever run — inside {@see WorkerChild::main()}, after the fork
 * has already happened.
 *
 * # What is inherited and what is not
 *
 * Memory and open descriptors are inherited; **interval timers are not**, and signal *handlers* are
 * inherited but usually wrong for the child. Both are dealt with in {@see self::runChild()}.
 */
final class ProcessWorker implements WorkerInterface
{
    /** How long {@see self::terminate()} waits for a `SIGKILL`ed child to actually be reaped. */
    private const float KILL_WAIT_SECONDS = 1.0;

    /** Polling granularity while waiting for a child to exit; 1ms keeps a grace period honest. */
    private const int REAP_POLL_MICROSECONDS = 1000;

    private ?ControlSocket $control;

    private bool $shutdownRequested = false;

    private bool $reaped = false;

    private ?int $exitStatus = null;

    private ?int $termSignal = null;

    private function __construct(
        private readonly int $id,
        private readonly int $pid,
        ControlSocket $control,
    ) {
        $this->control = $control;
    }

    /**
     * Fork a worker and return the parent's handle on it. **Never returns in the child.**
     *
     * @param (\Closure(int): void)|null $afterFork Runs in the child immediately after the fork,
     *                                              before any scheduler or fiber exists.
     *
     *   # Seam for ticket #5 (preemption)
     *
     *   This is where a child re-arms its own preemption timer. `setitimer` intervals are **not**
     *   inherited across `fork()`, so a child of a preemptive parent runs cooperatively — silently,
     *   with no error — unless it arms its own. Installing the SIGALRM handler is not enough either;
     *   the interval itself has to be set again on this side of the fork.
     */
    public static function fork(int $id, TaskDirectory $tasks, ?\Closure $afterFork = null): self
    {
        self::requireProcessControl();

        [$parentEnd, $childEnd] = ControlSocket::pair();

        $pid = pcntl_fork();

        if ($pid === -1) {
            $parentEnd->close();
            $childEnd->close();

            throw new \RuntimeException(sprintf('unable to fork worker #%d', $id));
        }

        if ($pid === 0) {
            // ---------------------------------------------------------------- child
            $parentEnd->close();

            exit(self::runChild($id, $childEnd, $tasks, $afterFork));
        }

        // ---------------------------------------------------------------- parent
        $childEnd->close();

        return new self($id, $pid, $parentEnd);
    }

    public function id(): int
    {
        return $this->id;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function dispatch(int $slotId, int $taskAddress): void
    {
        if (!$this->isAlive()) {
            throw WorkerCrashedException::notRunning($this->id);
        }

        $control = $this->control ?? throw WorkerCrashedException::notRunning($this->id);

        // The record names the slot and the address. The task itself never touches this socket.
        $control->send(new ControlRecord(
            Opcode::SPAWN,
            $slotId,
            TaggedRecord::address(Tag::OBJ, $taskAddress),
        ));
    }

    /**
     * @return resource|null
     */
    public function readinessFd()
    {
        $control = $this->control;

        return $control !== null && $control->isOpen() ? $control->stream() : null;
    }

    /**
     * Whole records the worker has sent since the last call.
     *
     * @return list<ControlRecord>
     */
    public function receive(): array
    {
        return $this->control?->drain() ?? [];
    }

    /** Whether the worker has closed its end — the parent's signal that the child is finishing. */
    public function isControlEof(): bool
    {
        return $this->control === null || $this->control->isEof();
    }

    public function isAlive(): bool
    {
        return !$this->tryReap();
    }

    /**
     * Send the polite rung and return immediately.
     *
     * Split from {@see self::shutdown()} so a pool can ask every worker to stop and then wait once,
     * instead of paying the grace period N times over.
     */
    public function requestShutdown(): void
    {
        $this->shutdownRequested = true;

        if (!$this->isAlive()) {
            return;
        }

        try {
            $this->control?->send(new ControlRecord(Opcode::SHUTDOWN));
        } catch (\Throwable) {
            // The worker is already going; the harder rungs of the ladder will confirm it.
        }
    }

    public function shutdown(float $graceSeconds): bool
    {
        $this->requestShutdown();

        return $this->waitForExit($graceSeconds);
    }

    public function terminate(): void
    {
        if ($this->isAlive()) {
            $this->signal(SIGKILL);
            $this->waitForExit(self::KILL_WAIT_SECONDS);
        }

        $this->closeControl();
    }

    /** Deliver a signal, if the worker is still there to receive one. */
    public function signal(int $signal): void
    {
        if (!$this->isAlive()) {
            return;
        }

        @posix_kill($this->pid, $signal);
    }

    /**
     * Non-blocking reap. Idempotent, and the single place an exit status is recorded.
     *
     * @return bool Whether the child has been reaped — by this call or by an earlier one.
     */
    public function tryReap(): bool
    {
        if ($this->reaped) {
            return true;
        }

        $status = 0;
        $result = pcntl_waitpid($this->pid, $status, WNOHANG);

        if ($result === 0) {
            return false;
        }

        $this->reaped = true;

        // -1 means there is no such child any more: somebody else reaped it, which is not something
        // this class can recover a status from, only record as "gone".
        if ($result !== $this->pid || !is_int($status)) {
            return true;
        }

        $exit = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : false;
        if ($exit !== false) {
            $this->exitStatus = $exit;
        }

        $signal = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : false;
        if ($signal !== false) {
            $this->termSignal = $signal;
        }

        return true;
    }

    /** Poll for the child's exit for at most $seconds. */
    public function waitForExit(float $seconds): bool
    {
        $deadline = microtime(true) + max(0.0, $seconds);

        while (true) {
            if ($this->tryReap()) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return $this->tryReap();
            }

            usleep(self::REAP_POLL_MICROSECONDS);
        }
    }

    /** Whether this worker was asked to stop, which is what separates an exit from a death. */
    public function wasShutdownRequested(): bool
    {
        return $this->shutdownRequested;
    }

    public function exitStatus(): ?int
    {
        return $this->exitStatus;
    }

    public function termSignal(): ?int
    {
        return $this->termSignal;
    }

    public function closeControl(): void
    {
        $this->control?->close();
        $this->control = null;
    }

    /**
     * Describe this worker's death as an exception a waiter can be failed with.
     *
     * @param list<int> $abandonedSlots Slots that can now never complete.
     */
    public function crashException(array $abandonedSlots = []): WorkerCrashedException
    {
        $this->tryReap();

        $signal = $this->termSignal;
        $status = $this->exitStatus;

        if ($abandonedSlots === []) {
            return match (true) {
                $signal !== null => WorkerCrashedException::killedBySignal($this->id, $signal),
                $status !== null => WorkerCrashedException::exitedWithStatus($this->id, $status),
                default          => WorkerCrashedException::notRunning($this->id),
            };
        }

        $reason = match (true) {
            $signal !== null => sprintf('killed by signal %d', $signal),
            $status !== null => sprintf('exited with status %d', $status),
            default          => 'the worker is not running',
        };

        return new WorkerCrashedException($this->id, $reason, $abandonedSlots);
    }

    /**
     * The child's whole life: reset what it must not inherit, then serve the inbox.
     *
     * @param (\Closure(int): void)|null $afterFork
     */
    private static function runChild(
        int $id,
        ControlSocket $control,
        TaskDirectory $tasks,
        ?\Closure $afterFork,
    ): int {
        // Output buffers are inherited with their contents. Without this, anything the parent had
        // buffered but not flushed at fork time is printed a second time when this process exits.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Signal dispositions survive fork. The parent's SIGCHLD reaper would fire here for children
        // this process does not have, and a SIGTERM handler meant for the pool is not this worker's.
        pcntl_signal(SIGCHLD, SIG_DFL);
        pcntl_signal(SIGTERM, SIG_DFL);

        // SEAM (#5, preemption): interval timers are NOT inherited across fork. A child of a
        // preemptive parent has no timer at all until this closure arms one.
        if ($afterFork !== null) {
            $afterFork($id);
        }

        try {
            // Only now — after the fork — does a scheduler, and with it a fiber, come into being.
            return WorkerChild::main($control, $tasks);
        } catch (\Throwable $panic) {
            fwrite(STDERR, sprintf(
                'worker #%d died in its inbox loop: %s: %s%s',
                $id,
                $panic::class,
                $panic->getMessage(),
                PHP_EOL,
            ));

            return 70;
        }
    }

    private static function requireProcessControl(): void
    {
        foreach (['pcntl_fork', 'pcntl_waitpid', 'posix_kill'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException(sprintf(
                    'parallel workers need ext-pcntl and ext-posix; %s() is not available',
                    $function,
                ));
            }
        }
    }
}
