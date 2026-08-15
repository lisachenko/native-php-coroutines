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

namespace Lisachenko\NativePhpCoroutines\Internal;

use Lisachenko\NativePhpCoroutines\CoroutineInterface;
use Lisachenko\NativePhpCoroutines\SchedulerInterface;
use Lisachenko\NativePhpCoroutines\SuspendCommand;

/**
 * The park/unpark bookkeeping every blocking primitive repeats.
 *
 * Three rules live here so that no primitive can get them subtly different:
 *
 * - **Park before suspending.** The wait description is what a deadlock dump prints, so it is
 *   recorded while the coroutine still knows why it is blocking.
 * - **Unpark, then schedule.** {@see CoroutineInterface::unpark()} performs the state transition
 *   and reports whether *this* caller performed it; only that caller may enqueue the coroutine.
 *   Doing both unconditionally would run a coroutine twice whenever two primitives race to wake it,
 *   which is the normal case in a `select`.
 * - **Blocking outside a coroutine is a programming error, not a hang.** There is nothing to hand
 *   control back to, so the operation says so instead of parking a process that can never resume.
 */
abstract class ParkingPrimitive
{
    public function __construct(protected readonly SchedulerInterface $scheduler) {}

    /**
     * The coroutine that is about to block, or a hard error when there is none.
     *
     * @param string $operation Named in the error, so the message points at the call that blocked.
     */
    final protected function blockingCoroutine(string $operation): CoroutineInterface
    {
        $coroutine = $this->scheduler->current();
        if ($coroutine === null) {
            throw new \LogicException(
                sprintf('%s would block, but it was called outside a coroutine', $operation),
            );
        }

        return $coroutine;
    }

    /**
     * Hand this coroutine over to the primitive and give control back to the scheduler.
     *
     * Returns once somebody has unparked it; the outcome is read from the caller's own wait node or
     * state, never from the resume value, because a scheduler is free to resume with whatever it
     * likes.
     */
    final protected function parkAndSuspend(CoroutineInterface $coroutine, string $waitDescription): void
    {
        $coroutine->park($waitDescription);
        $this->scheduler->suspend(SuspendCommand::BLOCKED);
    }

    /**
     * Make a parked coroutine runnable, exactly once.
     *
     * A false return means somebody else already woke it — a losing `select` case, or a second
     * waker in the same tick — and enqueueing it here would schedule it twice.
     */
    final protected function wake(CoroutineInterface $coroutine): void
    {
        if ($coroutine->unpark()) {
            $this->scheduler->schedule($coroutine);
        }
    }
}
