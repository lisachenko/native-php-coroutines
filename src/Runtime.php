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

/**
 * The composition root of a process.
 *
 * At this point it composes Layer 1 only: a scheduler, its timer heap and its poller. `workers: 0`
 * and `preemptive: false` are therefore the only accepted configuration, and everything that needs
 * FFI — shared roots, the arena, forked workers — refuses with a message naming the ticket that
 * will implement it rather than half-working.
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

    /**
     * @param int  $workers    Number of forked workers; only 0 (this process alone) is supported yet.
     * @param bool $preemptive Whether to force time slices; Layer 2, not supported yet.
     */
    public function __construct(private readonly int $workers = 0, private readonly bool $preemptive = false)
    {
        if ($workers !== 0) {
            throw new \LogicException(self::notYet('parallel workers are', 7) . sprintf(
                '; construct the runtime with workers: 0 instead of %d',
                $workers,
            ));
        }

        if ($preemptive) {
            throw new \LogicException(self::notYet('preemptive scheduling is', 5)
                . '; construct the runtime with preemptive: false');
        }

        $this->scheduler = new Scheduler();
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
        $mainCoroutine = $this->scheduler->spawn(fn(): mixed => $main($this));

        $this->scheduler->runUntil($mainCoroutine);
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

    /** Whether preemption was requested; false until Layer 2 lands. */
    public function isPreemptive(): bool
    {
        return $this->preemptive;
    }

    /** @param string $subject The refused feature, verb included, e.g. "parallel workers are". */
    private static function notYet(string $subject, int $ticket): string
    {
        return sprintf('%s not implemented yet (see #%d)', $subject, $ticket);
    }
}
