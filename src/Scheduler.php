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

use Lisachenko\NativePhpCoroutines\Exception\DeadlockException;

/**
 * The cooperative scheduler of one process: a FIFO run queue, a timer heap, and one poller.
 *
 * # The idle turn
 *
 * Everything interesting happens when the run queue empties. The scheduler then, in order:
 *
 * 1. fires every timer whose deadline has passed — if any fired, somebody is runnable again;
 * 2. computes the earliest remaining deadline and hands it to {@see PollerInterface::poll()} as a
 *    timeout, so a program that is only sleeping blocks in the kernel instead of spinning;
 * 3. and if there is neither a deadline nor a registered descriptor, concludes that nothing can
 *    ever make a coroutine runnable again — a deadlock.
 *
 * A coroutine parked as *externally wakeable* is excluded from that conclusion: its wakeup was
 * never the scheduler's to produce. That exclusion is what keeps an idle server from reporting
 * itself deadlocked every time it has nothing to do.
 */
final class Scheduler implements SchedulerInterface
{
    /** The scheduler the static surfaces (`Coroutine::spawn()`, `Io::…`, `Timer::…`) talk to. */
    private static ?self $active = null;

    /** @var \SplQueue<Coroutine> */
    private readonly \SplQueue $runQueue;

    private readonly TimerQueue $timers;

    private readonly PollerInterface $poller;

    /**
     * Every coroutine that has not finished, in spawn order — the deadlock dump reads this.
     *
     * @var array<int, Coroutine>
     */
    private array $live = [];

    private ?Coroutine $current = null;

    public function __construct(?PollerInterface $poller = null)
    {
        /** @var \SplQueue<Coroutine> $queue */
        $queue          = new \SplQueue();
        $this->runQueue = $queue;
        $this->timers   = new TimerQueue();
        $this->poller   = $poller ?? new StreamPoller($this);

        self::$active = $this;
    }

    /**
     * The scheduler of this process.
     *
     * There is exactly one per process by design; constructing a scheduler makes it the active one,
     * which is how a forked worker takes over from the parent's.
     */
    public static function active(): self
    {
        return self::$active ?? throw new \LogicException(
            'no scheduler is active in this process; create a Runtime (or a Scheduler) before '
            . 'spawning coroutines',
        );
    }

    public function spawn(\Closure $body, mixed ...$arguments): CoroutineInterface
    {
        $coroutine = new Coroutine($body, array_values($arguments));

        $this->live[$coroutine->id()] = $coroutine;
        $this->schedule($coroutine);

        return $coroutine;
    }

    public function current(): ?CoroutineInterface
    {
        return $this->current;
    }

    public function schedule(CoroutineInterface $coroutine): void
    {
        if (!$coroutine instanceof Coroutine) {
            throw new \InvalidArgumentException(sprintf(
                'this scheduler drives %s instances, got %s',
                Coroutine::class,
                $coroutine::class,
            ));
        }

        if ($coroutine->status() !== CoroutineStatus::READY) {
            throw new \LogicException(sprintf(
                'coroutine #%d is %s and cannot be put on the run queue; unpark it first',
                $coroutine->id(),
                $coroutine->status()->name,
            ));
        }

        // Two primitives racing to wake the same coroutine both call schedule() after a successful
        // unpark only in buggy code, but running a coroutine twice is a corruption, not a warning.
        if ($coroutine->isQueued()) {
            return;
        }

        $coroutine->markQueued();
        $this->runQueue->enqueue($coroutine);
    }

    public function suspend(SuspendCommand $command): mixed
    {
        if ($this->current === null) {
            throw new \LogicException('suspend() is only possible inside a running coroutine');
        }

        if (\Fiber::getCurrent() === null) {
            throw new \LogicException('suspend() was called outside the fiber of the running coroutine');
        }

        return \Fiber::suspend($command);
    }

    public function poller(): PollerInterface
    {
        return $this->poller;
    }

    /** The pending deadlines; the timer surface and `sleep()` arm their entries here. */
    public function timers(): TimerQueue
    {
        return $this->timers;
    }

    /**
     * Park the running coroutine until $seconds have passed.
     *
     * A non-positive duration is a plain yield: the coroutine gives up the CPU, but arming a timer
     * for a deadline that has already passed only makes the loop take a detour to the heap.
     */
    public function sleep(float $seconds): void
    {
        if ($this->current === null) {
            throw new \LogicException('sleep() is only possible inside a running coroutine');
        }

        if ($seconds <= 0.0) {
            $this->suspend(SuspendCommand::YIELD);

            return;
        }

        $coroutine = $this->current;
        $coroutine->park(sprintf('sleep for %ss', rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.')));

        $this->timers->arm($seconds, function () use ($coroutine): void {
            if ($coroutine->unpark()) {
                $this->schedule($coroutine);
            }
        });

        $this->suspend(SuspendCommand::SLEEP);
    }

    public function loop(): void
    {
        $this->drive(null);
    }

    /**
     * Run until $coroutine finishes, then stop — whatever else is still pending.
     *
     * This is Go's `main`: the process does not wait for stragglers, it ends. Coroutines still on
     * the run queue, parked on a timer or parked on the poller are dropped, and dropping them is
     * final — they are never resumed, so their `finally` blocks do not run, exactly as a
     * goroutine's deferred calls do not run when main returns.
     */
    public function runUntil(CoroutineInterface $coroutine): void
    {
        try {
            $this->drive($coroutine);
        } finally {
            $this->discardPending();
        }
    }

    /**
     * Forget everything that is still pending: run queue, timer heap and poller registrations.
     *
     * A panic leaves the same debris behind as a returning main, so this runs after either.
     */
    public function discardPending(): void
    {
        while (!$this->runQueue->isEmpty()) {
            $this->runQueue->dequeue()->markDequeued();
        }

        while (!$this->timers->isEmpty()) {
            $this->timers->extract();
        }

        $this->live = [];

        if ($this->poller instanceof StreamPoller) {
            $this->poller->forgetAll();
        }
    }

    /**
     * @throws DeadlockException
     */
    private function drive(?CoroutineInterface $until): void
    {
        self::$active = $this;

        try {
            while (true) {
                if ($until !== null && $until->status() === CoroutineStatus::DONE) {
                    return;
                }

                if (!$this->runQueue->isEmpty()) {
                    $this->runNext();

                    continue;
                }

                $now = TimerQueue::now();

                // A fired timer has just unparked somebody (or spawned something), so the run
                // queue is worth another look before considering the process idle.
                if ($this->timers->fireDue($now) > 0) {
                    continue;
                }

                $timeout    = $this->timers->timeUntilNextDeadline($now);
                $hasWatches = $this->poller->hasWatches();

                if ($timeout === null && !$hasWatches) {
                    $blocked = $this->blockedCoroutines();

                    if ($blocked !== []) {
                        throw new DeadlockException($blocked);
                    }

                    // Nothing runnable, nothing pending, nobody waiting on anything local: the
                    // work is done. Anything still parked here is externally wakeable with no
                    // registered descriptor, which only a cross-process primitive can produce.
                    return;
                }

                $this->poller->poll($timeout);
            }
        } finally {
            $this->current = null;
        }
    }

    private function runNext(): void
    {
        $coroutine = $this->runQueue->dequeue();
        $coroutine->markDequeued();

        // Unparked, scheduled, and then finished or re-parked before its turn came up.
        if ($coroutine->status() !== CoroutineStatus::READY) {
            return;
        }

        $this->current = $coroutine;

        try {
            $command = $coroutine->step();
        } catch (\Throwable $panic) {
            // An uncaught throwable is a panic: it ends the run and surfaces at the caller of
            // run()/loop(), exactly where a synchronous exception would have.
            unset($this->live[$coroutine->id()]);

            throw $panic;
        } finally {
            $this->current = null;
        }

        if ($command === null) {
            unset($this->live[$coroutine->id()]);

            return;
        }

        if ($command->staysRunnable()) {
            $this->schedule($coroutine);
        }

        // Otherwise the coroutine handed itself to a primitive, a timer or the poller, and only an
        // unpark from that owner may put it back on this queue.
    }

    /**
     * The coroutines a deadlock report is about: blocked, and waiting on something local.
     *
     * @return list<array{id: int, wait: string, origin: string}>
     */
    private function blockedCoroutines(): array
    {
        $blocked = [];

        foreach ($this->live as $coroutine) {
            if ($coroutine->status() !== CoroutineStatus::BLOCKED || $coroutine->isExternallyWakeable()) {
                continue;
            }

            $blocked[] = [
                'id'     => $coroutine->id(),
                'wait'   => $coroutine->waitDescription() ?? 'an unnamed wait',
                'origin' => $coroutine->spawnLocation(),
            ];
        }

        return $blocked;
    }
}
