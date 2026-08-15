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

namespace Lisachenko\NativePhpCoroutines;

use Lisachenko\NativePhpCoroutines\Parallel\Task;
use Lisachenko\NativePhpCoroutines\Preemption\Preemptor;

/**
 * The composition root of a process.
 *
 * At this point it composes Layers 1 and 2: a scheduler, its timer heap, its poller, and — only
 * when asked for — the preemptor that takes the CPU back from a coroutine that will not give it up.
 * `workers: 0` is still the only accepted worker count, and everything that needs the shared arena
 * refuses with a message naming the ticket that will implement it rather than half-working.
 *
 * # Preemption is opt-in, and that is a property of the build, not a preference
 *
 * `preemptive: false` composes exactly what Layer 1 composed: no FFI binding, no engine hook, no
 * signal handler, and no dependency on ext-ffi or ext-pcntl being present at all. `preemptive: true`
 * adds {@see Preemptor}, which needs both, and which is armed for the duration of {@see self::run()}
 * and drained and disarmed on the way out — including when the run ends in a panic.
 *
 * # What `run()` guarantees
 *
 * Go semantics, deliberately: when the main coroutine returns, the run is over. Coroutines still
 * queued, sleeping or parked on the poller are **discarded**, not awaited — a program that wants to
 * wait for its workers says so with a WaitGroup or a channel. An uncaught Throwable anywhere is a
 * panic: it ends the run and comes back out of `run()`.
 */
final class Runtime implements RuntimeInterface
{
    private readonly Scheduler $scheduler;

    private readonly ?Preemptor $preemptor;

    /**
     * @param int   $workers    Number of forked workers; only 0 (this process alone) is supported yet.
     * @param bool  $preemptive Whether to force time slices on coroutines that do not yield.
     * @param float $slice      Target slice length in seconds when $preemptive; a target, not a bound.
     */
    public function __construct(
        private readonly int $workers = 0,
        private readonly bool $preemptive = false,
        float $slice = Preemptor::DEFAULT_SLICE_SECONDS,
    ) {
        if ($workers !== 0) {
            throw new \LogicException(self::notYet('parallel workers are', 7) . sprintf(
                '; construct the runtime with workers: 0 instead of %d',
                $workers,
            ));
        }

        $this->scheduler = new Scheduler();
        $this->preemptor = $preemptive ? new Preemptor($this->scheduler, $slice) : null;

        $this->scheduler->attachPreemptor($this->preemptor);
    }

    public function declareShared(string $name, string $class, int $capacity = 0): void
    {
        throw new \LogicException(self::notYet('shared roots are', 7));
    }

    public function shared(string $name): mixed
    {
        throw new \LogicException(self::notYet('shared roots are', 7));
    }

    public function persist(object $object): object
    {
        throw new \LogicException(self::notYet('persisting objects into the shared arena is', 7));
    }

    public function spawnParallel(Task $task, ?int $worker = null): JoinHandleInterface
    {
        throw new \LogicException(self::notYet('parallel workers are', 7));
    }

    public function run(\Closure $main): void
    {
        $this->preemptor?->arm();

        try {
            $mainCoroutine = $this->scheduler->spawn(fn(): mixed => $main($this));

            $this->scheduler->runUntil($mainCoroutine);
        } finally {
            // Drains every preempted coroutine out of the interrupt callback before the timer
            // stops, then stops it. Both halves matter even on the panic path: what is left
            // suspended inside that callback is fatal at shutdown, not merely leaked.
            $this->preemptor?->disarm();
        }
    }

    public function scheduler(): SchedulerInterface
    {
        return $this->scheduler;
    }

    /** Whether this runtime was asked for parallel workers; 0 until the parallel layer lands. */
    public function workers(): int
    {
        return $this->workers;
    }

    /** Whether this runtime forces time slices on its coroutines. */
    public function isPreemptive(): bool
    {
        return $this->preemptive;
    }

    /**
     * The preemptor, or null on a cooperative runtime.
     *
     * This is the handle application code needs for
     * {@see Preemptor::enterCriticalSection()}/{@see Preemptor::leaveCriticalSection()} — the only
     * way to mark a stretch of code that must not lose the CPU halfway through.
     */
    public function preemptor(): ?Preemptor
    {
        return $this->preemptor;
    }

    /** @param string $subject The refused feature, verb included, e.g. "parallel workers are". */
    private static function notYet(string $subject, int $ticket): string
    {
        return sprintf('%s not implemented yet (see #%d)', $subject, $ticket);
    }
}
