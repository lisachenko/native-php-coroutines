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
 * The scheduler's pending deadlines, ordered earliest first.
 *
 * The earliest deadline is the poller's timeout, which is the entire point of keeping timers in a
 * heap: `top()` is O(1), so on every idle turn the scheduler can say "block until this instant"
 * instead of polling with a zero timeout and spinning.
 *
 * @extends \SplMinHeap<TimerEntry>
 * @internal
 */
final class TimerQueue extends \SplMinHeap
{
    private int $nextId = 1;

    /** Monotonic nanoseconds. The one clock this runtime measures durations with. */
    public static function now(): int
    {
        return hrtime(true);
    }

    /**
     * Arm a timer $seconds from now.
     *
     * @param \Closure(): void $onFire Runs on the scheduler's own stack, not inside a coroutine, so
     *                                 it must not suspend; spawn a coroutine from it to do that.
     */
    public function arm(float $seconds, \Closure $onFire): TimerEntry
    {
        $delay = $seconds > 0.0 ? (int) round($seconds * 1_000_000_000) : 0;
        $entry = new TimerEntry($this->nextId++, self::now() + $delay, $onFire);

        $this->insert($entry);

        return $entry;
    }

    /**
     * Fire every timer whose deadline has passed, earliest first.
     *
     * @return int How many fired — a non-zero answer means the scheduler has work again and must
     *             not go back to the poller.
     */
    public function fireDue(int $now): int
    {
        $fired = 0;

        // A timer that re-arms itself with a past deadline would otherwise keep this loop going
        // forever, so one pass fires at most the timers that were already queued when it started.
        for ($budget = $this->count(); $budget > 0 && !$this->isEmpty(); --$budget) {
            $entry = $this->top();

            if ($entry->isCancelled()) {
                $this->extract();

                continue;
            }

            if ($entry->deadline > $now) {
                break;
            }

            $this->extract();
            ++$fired;
            ($entry->onFire)();
        }

        return $fired;
    }

    /**
     * Seconds until the earliest live deadline, or null when no timer is pending.
     *
     * This is the value the scheduler hands to {@see PollerInterface::poll()}.
     */
    public function timeUntilNextDeadline(int $now): ?float
    {
        $this->dropCancelled();

        if ($this->isEmpty()) {
            return null;
        }

        return max(0.0, ($this->top()->deadline - $now) / 1_000_000_000);
    }

    /**
     * Order by deadline, ties broken by arming order.
     *
     * `SplMinHeap` wants a positive number when $value1 belongs closer to the root, which is the
     * reverse of the usual comparator — hence the operands being the other way round.
     *
     * @param TimerEntry $value1
     * @param TimerEntry $value2
     */
    protected function compare(mixed $value1, mixed $value2): int
    {
        $byDeadline = $value2->deadline <=> $value1->deadline;

        return $byDeadline !== 0 ? $byDeadline : $value2->id <=> $value1->id;
    }

    /** Cancelled entries are only reachable at the top, and only there can they be dropped. */
    private function dropCancelled(): void
    {
        while (!$this->isEmpty() && $this->top()->isCancelled()) {
            $this->extract();
        }
    }
}
