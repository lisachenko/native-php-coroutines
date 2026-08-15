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

namespace Lisachenko\NativePhpCoroutines\Sync;

use Lisachenko\NativePhpCoroutines\CoroutineInterface;
use Lisachenko\NativePhpCoroutines\Exception\DeadlockException;
use Lisachenko\NativePhpCoroutines\Internal\ParkingPrimitive;
use Lisachenko\NativePhpCoroutines\SchedulerInterface;

/**
 * A cooperative, FIFO, non-reentrant mutual exclusion lock.
 *
 * Cooperative code does not need a lock to protect a plain critical section — nothing preempts a
 * coroutine between two statements. It needs one around a section that *suspends*: the moment a
 * coroutine awaits a channel or an IO readiness in the middle of updating shared state, another
 * coroutine can walk into the same code and see it half-updated.
 *
 * # Non-reentrant, and loudly so
 *
 * Locking a mutex this coroutine already holds can only ever end in waiting for itself. A reentrant
 * lock would be a different primitive with different guarantees — recursive critical sections stop
 * being atomic at the outer level — so this one reports the mistake as the deadlock it is, right at
 * the offending `lock()`, instead of hanging until the scheduler runs out of work and blames some
 * unrelated coroutine.
 *
 * # FIFO with direct handoff
 *
 * `unlock()` transfers ownership to the longest-waiting coroutine before waking it, rather than
 * unlocking and letting whoever runs next race for it. Without the handoff a coroutine that locks
 * in a tight loop can barge in front of the queue every time and starve it.
 */
final class Mutex extends ParkingPrimitive
{
    private static int $nextId = 1;

    private readonly int $id;

    private ?CoroutineInterface $owner = null;

    /** @var list<CoroutineInterface> */
    private array $waiters = [];

    public function __construct(SchedulerInterface $scheduler)
    {
        parent::__construct($scheduler);

        $this->id = self::$nextId++;
    }

    /**
     * Acquire the lock, parking until it is free.
     *
     * @throws DeadlockException When the calling coroutine already holds this mutex.
     */
    public function lock(): void
    {
        $coroutine = $this->blockingCoroutine(sprintf('Mutex #%d lock', $this->id));

        if ($this->owner === $coroutine) {
            throw new DeadlockException([[
                'id'     => $coroutine->id(),
                'wait'   => sprintf('lock on Mutex #%d, which this coroutine already holds', $this->id),
                'origin' => $coroutine->spawnLocation(),
            ]]);
        }

        if ($this->owner === null) {
            $this->owner = $coroutine;

            return;
        }

        $this->waiters[] = $coroutine;
        $this->parkAndSuspend($coroutine, sprintf('lock on Mutex #%d', $this->id));

        // Ownership was transferred by unlock() before it woke this coroutine; there is nothing
        // left to acquire and nothing to race for.
    }

    /**
     * Acquire the lock only if it is free right now.
     *
     * Returns false for a reentrant attempt rather than throwing: the caller is explicitly asking
     * whether the lock is available, and the honest answer is "not to you".
     */
    public function tryLock(): bool
    {
        if ($this->owner !== null) {
            return false;
        }

        $coroutine = $this->scheduler->current();
        if ($coroutine === null) {
            throw new \LogicException('Mutex::tryLock() was called outside a coroutine');
        }

        $this->owner = $coroutine;

        return true;
    }

    /**
     * Release the lock, handing it to the longest-waiting coroutine if there is one.
     *
     * Releasing from a coroutine other than the one that locked is allowed — the lock-here,
     * release-there handoff is a legitimate pattern — but releasing an unlocked mutex is not: it
     * means the caller has lost track of who owns what.
     */
    public function unlock(): void
    {
        if ($this->owner === null) {
            throw new \LogicException(sprintf('Mutex #%d is not locked', $this->id));
        }

        $next = array_shift($this->waiters);
        if ($next === null) {
            $this->owner = null;

            return;
        }

        $this->owner = $next;
        $this->wake($next);
    }

    public function isLocked(): bool
    {
        return $this->owner !== null;
    }

    /** How many coroutines are parked waiting for this lock. */
    public function pendingWaiters(): int
    {
        return count($this->waiters);
    }
}
