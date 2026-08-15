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
 * Deadline callbacks on the active scheduler.
 *
 * `Coroutine::sleep()` parks a coroutine; this parks nothing. The callback runs on the scheduler's
 * own stack once the deadline passes, which makes it the right tool for a timeout that has to fire
 * whether or not anybody is waiting for it — and the wrong place to suspend, since there is no
 * coroutine there to suspend.
 */
final class Timer
{
    /**
     * Run $callback once, $seconds from now.
     *
     * @param \Closure(): void $callback
     * @return TimerEntry Handle to cancel it with, while it is still pending.
     */
    public static function after(float $seconds, \Closure $callback): TimerEntry
    {
        return Scheduler::active()->timers()->arm($seconds, $callback);
    }

    /** Whether a pending timer was cancelled by this call; false if it had already fired. */
    public static function cancel(TimerEntry $timer): bool
    {
        return $timer->cancel();
    }
}
