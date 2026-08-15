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
 * One armed timer: a monotonic deadline and what to do when it passes.
 *
 * The deadline is in `hrtime(true)` nanoseconds, not wall-clock microseconds. A timer heap keyed on
 * `microtime()` would fire early or late whenever the system clock is stepped — NTP, a container
 * resuming, an operator fixing the date — and a runtime whose sleeps depend on that is not a
 * runtime anybody can reason about.
 *
 * Cancelling is a flag rather than a removal: an `SplMinHeap` has no O(log n) delete, and a
 * cancelled entry costs nothing but the moment it reaches the top and is dropped unfired.
 */
final class TimerEntry
{
    private bool $cancelled = false;

    /**
     * @param int            $id       Process-unique, and the tiebreaker between equal deadlines,
     *                                 which is what keeps same-deadline timers in arming order.
     * @param int            $deadline Monotonic nanoseconds, as returned by `hrtime(true)`.
     * @param \Closure(): void $onFire
     */
    public function __construct(
        public readonly int $id,
        public readonly int $deadline,
        public readonly \Closure $onFire,
    ) {}

    /** Idempotent, like unparking: true only for the call that actually cancelled. */
    public function cancel(): bool
    {
        if ($this->cancelled) {
            return false;
        }

        $this->cancelled = true;

        return true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
