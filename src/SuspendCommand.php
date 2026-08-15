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

/**
 * The reason a coroutine handed control back to its scheduler.
 *
 * This is the value a coroutine suspends *with*: the scheduler dispatches on it to decide what
 * happens to the coroutine next. The distinction that matters is whether the coroutine is still
 * runnable — a yielding coroutine goes back on the run queue immediately, a blocked one is now
 * owned by whatever it parked on and will not run again until that thing unparks it.
 */
enum SuspendCommand
{
    /** Voluntary hand-off. The coroutine is still runnable and goes to the tail of the run queue. */
    case YIELD;

    /**
     * The coroutine parked on a primitive (channel, wait group, mutex, join handle).
     *
     * Ownership passes to that primitive: the scheduler must NOT re-enqueue the coroutine, and
     * only an unpark from the primitive makes it runnable again.
     */
    case BLOCKED;

    /** The coroutine parked on a timer deadline and is owned by the timer heap. */
    case SLEEP;

    /** The coroutine parked on stream readiness and is owned by the poller. */
    case IO;

    /**
     * The scheduler forcibly took the CPU back (Layer 2).
     *
     * Semantically identical to {@see self::YIELD} for queueing purposes, but kept distinct
     * because a preempted coroutine did not choose to suspend: it must only ever be resumed with
     * `resume(null)` and never thrown into, since it is suspended at an arbitrary point rather
     * than at a call it wrote itself.
     */
    case PREEMPT;

    /**
     * Whether the coroutine stays runnable and should go straight back on the run queue.
     *
     * When this is false the coroutine has been handed to a primitive, a timer or the poller,
     * and re-enqueueing it would run it twice.
     */
    public function staysRunnable(): bool
    {
        return match ($this) {
            self::YIELD, self::PREEMPT           => true,
            self::BLOCKED, self::SLEEP, self::IO => false,
        };
    }

    /**
     * Whether a coroutine suspended this way may be resumed with an exception.
     *
     * Only false for {@see self::PREEMPT}: the suspension point is arbitrary, so an injected
     * throw would surface in code that has no idea it could throw there.
     */
    public function allowsThrow(): bool
    {
        return $this !== self::PREEMPT;
    }
}
